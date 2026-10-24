<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Enums\EmailEventType;
use App\Enums\MessageStatus;
use App\Enums\ProviderHealth;
use App\Enums\SendFailureKind;
use App\Models\EmailMessage;
use App\Models\EmailMessageAttempt;
use App\Models\SmtpProvider;
use App\Models\WarmupPlan;
use App\Services\Sending\EmailSender;
use App\Services\Sending\RecipientEligibility;
use App\Services\Sending\SendFailure;
use App\Services\Warmup\WarmupGate;
use Carbon\CarbonInterface;
use DateInterval;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Sends one email message. The message row is the source of truth: it is claimed atomically before
 * the provider is contacted, so a retried or duplicated job can never send the same message twice.
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

    public function handle(EmailSender $sender, RecipientEligibility $eligibility, WarmupGate $warmupGate): void
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

        $blockReason = $eligibility->blockReasonForMessage($message);

        if ($blockReason !== null) {
            $message->markSuppressed($blockReason);
            $message->campaign?->syncCompletionStatus();

            return;
        }

        $senderIdentity = $message->senderIdentity;

        if (! $senderIdentity->canSend()) {
            $message->markFailed("The sender identity {$senderIdentity->email} is not verified.", retryable: true);
            $message->campaign?->syncCompletionStatus();

            return;
        }

        $provider = $senderIdentity->smtpProvider;

        if (! $provider->is_enabled) {
            $message->markDeferred("The provider {$provider->name} is disabled.");
            $this->release(900);

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

        $rateLimitWait = $this->rateLimitWait($provider, $warmupPlan);

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
            $failure = SendFailure::fromException($exception, [(string) $provider->username]);
            $attempt->finish($this->attemptOutcome($failure), $failure->code, $failure->summary);
            $this->handleFailure($message, $provider, $failure);

            return;
        }

        $attempt->finish(EmailMessageAttempt::OUTCOME_ACCEPTED);
        $message->markAccepted($provider);

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
            $message->markFailed('Sending stopped after repeated errors: '.($exception?->getMessage() ?? 'unknown error'), retryable: true);
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

        // A campaign's messages are created together, so their ids give each one its place in line.
        $position = $message->getKey() - (int) $campaign->messages()->min('id');

        static::dispatch($message)->delay($availableAt->copy()->addSeconds((int) floor($position * 60 / max(1, $campaign->send_rate_per_minute))));
    }

    /**
     * Seconds to wait when the provider's or the warm-up plan's per-minute limit is used up. Checked only
     * after every other gate, so email that is waiting for another reason does not use up the budget.
     */
    private function rateLimitWait(SmtpProvider $provider, ?WarmupPlan $plan): ?int
    {
        $limits = array_filter([
            "smtp-provider:{$provider->getKey()}" => $provider->max_per_minute,
            "warmup-plan:{$plan?->getKey()}" => $plan?->max_per_minute,
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
