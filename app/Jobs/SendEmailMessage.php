<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Enums\EmailEventType;
use App\Enums\MessageStatus;
use App\Enums\ProviderHealth;
use App\Enums\SendFailureKind;
use App\Enums\SequenceStatus;
use App\Models\CampaignProspect;
use App\Models\EmailMessage;
use App\Models\EmailMessageAttempt;
use App\Models\SmtpProvider;
use App\Models\WarmupPlan;
use App\Services\Providers\MailProviderManager;
use App\Services\Sending\EmailSender;
use App\Services\Sending\RecipientEligibility;
use App\Services\Sending\SendFailure;
use App\Services\Tenancy\CurrentClient;
use App\Services\Warmup\WarmupGate;
use Carbon\CarbonInterface;
use DateInterval;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Sends one email message. The message row is the source of truth: it is claimed atomically before
 * the provider is contacted, so a retried or duplicated job can never send the same message twice.
 *
 * The provider is the sender identity's provider at the moment of sending, never a fallback. A sender
 * identity cannot move between a sandbox and a live provider while it has email waiting, so queued email
 * always goes to the environment it was queued for.
 */
class SendEmailMessage implements ShouldQueue
{
    use Batchable, Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(public EmailMessage $message)
    {
        $this->onQueue(config('prosend.sending.queue'));
    }

    /**
     * Laravel stores this deadline when the job is queued, so it is measured from when the job becomes due.
     */
    public function retryUntil(): CarbonInterface
    {
        $dueAt = match (true) {
            $this->delay instanceof DateTimeInterface => Carbon::instance($this->delay),
            $this->delay instanceof DateInterval => now()->add($this->delay),
            is_int($this->delay) => now()->addSeconds($this->delay),
            default => now(),
        };

        return $dueAt->addDays(2);
    }

    /**
     * Runs as the message's client, so every query inside is limited to that client's data.
     */
    public function handle(EmailSender $sender, RecipientEligibility $eligibility, WarmupGate $warmupGate, MailProviderManager $providers): void
    {
        app(CurrentClient::class)->runAsClientId($this->message->client_id, fn () => $this->process($sender, $eligibility, $warmupGate, $providers));
    }

    private function process(EmailSender $sender, RecipientEligibility $eligibility, WarmupGate $warmupGate, MailProviderManager $providers): void
    {
        $message = $this->message->refresh();

        if ($message->status === MessageStatus::Sending) {
            $this->resolveInterruptedSend($message);

            return;
        }

        if ($this->batch()?->cancelled() || ! $message->status->isAwaitingSend()) {
            return;
        }

        $campaignStatus = $message->campaign?->status;

        if ($campaignStatus === CampaignStatus::Paused) {
            return;
        }

        if ($campaignStatus === CampaignStatus::Cancelled) {
            $message->forceFill(['status' => MessageStatus::Cancelled])->save();
            $message->recordEvent(EmailEventType::Cancelled);

            return;
        }

        // The prospect replied, unsubscribed or was stopped after this email was queued.
        $endedSequence = $this->endedSequenceReason($message);

        if ($endedSequence !== null) {
            $message->cancelForEndedSequence($endedSequence);
            $message->campaign->syncCompletionStatus();

            return;
        }

        if ($message->contentWasPruned()) {
            $message->markFailed('The content of this email was deleted under the data retention policy, so it can no longer be sent.');

            return;
        }

        $blockReason = $eligibility->blockReasonForMessage($message);

        if ($blockReason !== null) {
            $message->markSuppressed($blockReason);
            $message->campaign?->syncCompletionStatus();

            return;
        }

        $senderIdentity = $message->senderIdentity;
        $provider = $senderIdentity->smtpProvider;

        // Fixed before any check that can fail the message, so a retry is always held to the first answer.
        $environmentProblem = $message->environmentProblem($provider);

        if ($environmentProblem !== null) {
            $message->markFailed($environmentProblem);
            $message->campaign?->syncCompletionStatus();

            return;
        }

        if (! $senderIdentity->canSend()) {
            $message->markFailed("The sender identity {$senderIdentity->email} is not verified.", retryable: true);
            $message->campaign?->syncCompletionStatus();

            return;
        }

        if (! $provider->is_enabled) {
            $message->markDeferred("The provider {$provider->name} is disabled.");
            $this->release(900);

            return;
        }

        $sendingRefusal = $provider->sendingRefusal();

        if ($sendingRefusal !== null) {
            $message->markFailed($sendingRefusal, retryable: true);
            $message->campaign?->syncCompletionStatus();

            return;
        }

        $windowOpensAt = $this->sendingWindowOpening($message);

        if ($windowOpensAt !== null) {
            $this->waitForSendingWindow($message, $windowOpensAt);

            return;
        }

        if ($provider->remainingQuota() === 0) {
            $this->waitUntil($message, now()->addDay()->startOfDay()->addMinute(), "The sending limit for {$provider->name} has been reached.");

            return;
        }

        $warmupPlan = $warmupGate->planFor($provider, $senderIdentity->email);
        $warmupDeferral = $warmupPlan ? $warmupGate->deferral($warmupPlan) : null;

        if ($warmupDeferral !== null) {
            $this->waitUntil($message, $warmupDeferral['until'], $warmupDeferral['reason']);

            return;
        }

        $rateLimitWait = $this->rateLimitWait($provider, $warmupPlan, $message);

        if ($rateLimitWait !== null) {
            $this->release($rateLimitWait);

            return;
        }

        if (! $message->claimForSending()) {
            return;
        }

        $attempt = $message->startAttempt($provider);

        try {
            $sender->send($message, $provider);
        } catch (TransportExceptionInterface|RfcComplianceException $exception) {
            $failure = $providers->driver($provider)->classifyFailure($exception, $provider);
            $this->finishAttempt($attempt, $this->attemptOutcome($failure), $failure->code, $failure->summary);
            $this->handleFailure($message, $provider, $failure);

            return;
        }

        // The message's own status comes first: it is what prevents a duplicate send.
        if ($provider->isSandbox()) {
            $message->markCaptured($provider);
            $this->finishAttempt($attempt, EmailMessageAttempt::OUTCOME_CAPTURED);
        } else {
            $message->markAccepted($provider);
            $this->finishAttempt($attempt, EmailMessageAttempt::OUTCOME_ACCEPTED);
        }

        if ($provider->health_status !== ProviderHealth::Healthy) {
            $provider->recordConnectionSuccess();
        }

        $message->campaign?->syncCompletionStatus();
    }

    /**
     * Runs once the job has exhausted its retry window or thrown too many unexpected exceptions.
     */
    public function failed(?Throwable $exception): void
    {
        $message = $this->message->fresh();

        if ($message === null) {
            return;
        }

        if ($message->status === MessageStatus::Sending) {
            $message->markAmbiguous('The send job stopped unexpectedly while the message was being submitted.');
        } elseif ($message->status->isAwaitingSend()) {
            // Exception messages can hold database or server details, and this text is shown to API clients, so only
            // the exception's type is kept here. The full error is in the application log.
            $message->markFailed('Sending stopped after repeated unexpected errors'.($exception ? ' ('.class_basename($exception).')' : '').'. The details are in the application log.', retryable: true);
        }

        $message->campaign?->syncCompletionStatus();
    }

    /**
     * A message still marked as sending was claimed by another attempt. While that claim is recent another
     * worker may be sending it right now, so leave it alone. Once it is stale the attempt died, and the
     * provider may or may not have the message.
     */
    private function resolveInterruptedSend(EmailMessage $message): void
    {
        if (! $message->hasStaleSendingClaim()) {
            return;
        }

        $message->markAmbiguous('A previous attempt stopped before the provider confirmed acceptance. It was not retried automatically, to avoid sending a duplicate.');
        $message->campaign?->syncCompletionStatus();
    }

    /**
     * Re-queue the message for later in a fresh job, which gets a fresh retry window, so an email can wait out
     * quota and warm-up limits for as long as needed. Campaign emails are spread out at the campaign's rate
     * instead of all becoming due at the same moment.
     */
    private function waitUntil(EmailMessage $message, CarbonInterface $availableAt, string $reason): void
    {
        if ($message->status !== MessageStatus::Deferred || $message->error_message !== $reason) {
            $message->markDeferred($reason);
        }

        $campaign = $message->campaign;

        if ($campaign === null) {
            static::dispatch($message)->delay($availableAt->copy()->addSeconds($message->getKey() % 600));

            return;
        }

        // Its place in line among the campaign's emails that are waiting too. Follow-ups are created days after the first
        // emails, so their ids alone would put them far back in the line.
        $position = $campaign->messages()->whereIn('status', [MessageStatus::Queued, MessageStatus::Deferred])->where('id', '<', $message->getKey())->count();

        static::dispatch($message)->delay($availableAt->copy()->addSeconds((int) floor($position * 60 / max(1, $campaign->send_rate_per_minute))));
    }

    /**
     * Seconds to wait when the provider's, the warm-up plan's, or the recipient domain's per-minute limit is
     * used up. Checked only after every other gate, so email that is waiting for another reason does not use
     * up the budget.
     */
    private function rateLimitWait(SmtpProvider $provider, ?WarmupPlan $plan, EmailMessage $message): ?int
    {
        $recipientDomain = Str::lower(Str::afterLast($message->to_email, '@'));

        $limits = array_filter([
            "smtp-provider:{$provider->getKey()}" => $provider->max_per_minute,
            "warmup-plan:{$plan?->getKey()}" => $plan?->max_per_minute,
            // Follow-ups and emails held back by a sending window can become due together; the campaign's rate still holds.
            "campaign:{$message->campaign_id}" => $message->campaign?->send_rate_per_minute,
            // Read as a whole array: domain names contain dots, which config() would treat as nested keys.
            "recipient-domain:{$recipientDomain}" => config('prosend.sending.recipient_domain_limits', [])[$recipientDomain] ?? null,
        ]);

        foreach ($limits as $key => $perMinute) {
            if (RateLimiter::tooManyAttempts($key, $perMinute)) {
                return RateLimiter::availableIn($key) + 1;
            }
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 60);
        }

        return null;
    }

    /**
     * Why a campaign email must not go out because its prospect's sequence ended, or null.
     */
    private function endedSequenceReason(EmailMessage $message): ?string
    {
        if ($message->campaign_id === null || $message->contact_id === null) {
            return null;
        }

        $prospect = CampaignProspect::query()
            ->where('campaign_id', $message->campaign_id)
            ->where('contact_id', $message->contact_id)
            ->first();

        if ($prospect === null) {
            return null;
        }

        // A last line of defence: a reply recorded since the prospect joined ends the sequence, however it was recorded.
        if ($prospect->isActive() && $message->step_number > 1 && $prospect->contact->hasRepliedSince($prospect->created_at)) {
            $prospect->finish(SequenceStatus::Replied, 'Replied');
        }

        return $prospect->isActive()
            ? null
            : "Not sent: the prospect's sequence ended ({$prospect->status->getLabel()}: {$prospect->finish_reason}).";
    }

    /**
     * When the campaign's sending window next opens for this recipient, or null when it is open now.
     */
    private function sendingWindowOpening(EmailMessage $message): ?CarbonInterface
    {
        $window = $message->campaign?->sendingWindowFor($message->contact);

        if ($window === null || $window->isUnrestricted() || $window->contains(now())) {
            return null;
        }

        return $window->nextOpening(now());
    }

    /**
     * Hold the email until the window opens. Emails released together are spread over the first minutes, and the
     * campaign's per-minute rate applies when they are sent.
     */
    private function waitForSendingWindow(EmailMessage $message, CarbonInterface $opensAt): void
    {
        $reason = 'Waiting for the campaign\'s sending window: '.$message->campaign->sendingWindowFor($message->contact)->describe().'.';

        if ($message->status !== MessageStatus::Deferred || $message->error_message !== $reason) {
            $message->markDeferred($reason);
        }

        static::dispatch($message)->delay($opensAt->copy()->addSeconds($message->getKey() % 300));
    }

    /**
     * The attempt record is an audit trail. Failing to write it must not stop the message's own status from being recorded.
     */
    private function finishAttempt(EmailMessageAttempt $attempt, string $outcome, ?string $code = null, ?string $summary = null): void
    {
        try {
            $attempt->finish($outcome, $code, $summary);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function attemptOutcome(SendFailure $failure): string
    {
        return match ($failure->kind) {
            SendFailureKind::Transient => EmailMessageAttempt::OUTCOME_DEFERRED,
            SendFailureKind::Permanent => EmailMessageAttempt::OUTCOME_FAILED,
            SendFailureKind::Ambiguous => EmailMessageAttempt::OUTCOME_AMBIGUOUS,
        };
    }

    private function handleFailure(EmailMessage $message, SmtpProvider $provider, SendFailure $failure): void
    {
        if ($failure->affectsProvider()) {
            $provider->recordConnectionFailure($failure->summary);
        }

        $retryDelays = config('prosend.sending.retry_delays');

        if ($failure->kind === SendFailureKind::Transient && $message->attempts <= count($retryDelays)) {
            $message->markDeferred($failure->summary, $failure->code);
            $this->release($retryDelays[$message->attempts - 1]);

            return;
        }

        match ($failure->kind) {
            SendFailureKind::Ambiguous => $message->markAmbiguous($failure->summary),
            default => $message->markFailed($failure->summary, $failure->code, $failure->retryable),
        };

        $message->campaign?->syncCompletionStatus();
    }
}
