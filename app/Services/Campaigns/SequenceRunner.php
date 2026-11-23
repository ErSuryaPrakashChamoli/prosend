<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\EmailEventType;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Enums\SequenceStatus;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignStep;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Services\Sending\RecipientEligibility;
use App\Services\Tenancy\CurrentClient;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues follow-ups that are due. Runs every minute from the scheduler; each run sends at most one minute's worth of
 * each campaign's rate. A prospect is claimed by clearing its due time in the same transaction that creates the email,
 * and each step's email is unique per campaign and contact, so overlapping runs can never send a step twice.
 */
class SequenceRunner
{
    public function __construct(
        private CurrentClient $currentClient,
        private RecipientEligibility $eligibility,
    ) {}

    /**
     * @return int The number of follow-ups queued.
     */
    public function run(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $queued = 0;

        // Follow-ups wait while a client is deactivated, and go out once it is active again.
        Campaign::acrossClients()
            ->where('status', CampaignStatus::Sending)
            ->whereNotNull('prepared_at')
            ->whereHas('client', fn ($clients) => $clients->where('is_active', true))
            ->whereHas('prospects', fn ($prospects) => $prospects->active()->where('next_step_due_at', '<=', $now))
            ->orderBy('id')
            ->each(function (Campaign $campaign) use ($now, &$queued): void {
                // One campaign that keeps failing must not hold up every other client's follow-ups.
                $queued += (int) rescue(fn (): int => $this->currentClient->runAs($campaign->client, fn (): int => $this->runCampaign($campaign, $now)), 0);
            });

        $this->settleCampaigns();

        return $queued;
    }

    /**
     * A backstop for completion: close active campaigns that have nothing left to send, and reopen a finished campaign
     * that still has follow-ups waiting (a completion check can race with the step that scheduled them).
     */
    private function settleCampaigns(): void
    {
        Campaign::acrossClients()
            ->where('status', CampaignStatus::Sending)
            ->whereNotNull('prepared_at')
            ->whereDoesntHave('prospects', fn ($prospects) => $prospects->active()->whereNotNull('next_step_due_at'))
            ->whereDoesntHave('messages', fn ($messages) => $messages->whereIn('status', MessageStatus::inFlight()))
            ->each(fn (Campaign $campaign) => rescue(fn () => $this->currentClient->runAs($campaign->client, fn () => $campaign->syncCompletionStatus())));

        Campaign::acrossClients()
            ->whereIn('status', [CampaignStatus::Completed, CampaignStatus::PartiallyFailed, CampaignStatus::Failed])
            ->whereNull('archived_at')
            ->whereHas('prospects', fn ($prospects) => $prospects->active()->whereNotNull('next_step_due_at'))
            ->each(fn (Campaign $campaign) => $campaign->transitionTo(CampaignStatus::Sending, from: [$campaign->status], attributes: ['completed_at' => null]));
    }

    private function runCampaign(Campaign $campaign, CarbonInterface $now): int
    {
        // Loaded once, so stepAfter() and the A/B versions do not query again for every prospect.
        $campaign->setRelation('steps', $campaign->steps()->with('variants')->get());

        $rate = max(1, $campaign->send_rate_per_minute);
        $queued = 0;

        $due = $campaign->prospects()
            ->active()
            ->where('next_step_due_at', '<=', $now)
            ->orderBy('next_step_due_at')
            ->limit($rate)
            ->get();

        foreach ($due as $prospect) {
            if ($this->queueNextStep($campaign, $prospect, delaySeconds: (int) floor($queued * 60 / $rate))) {
                $queued++;
            }
        }

        $campaign->syncCompletionStatus();

        return $queued;
    }

    private function queueNextStep(Campaign $campaign, CampaignProspect $prospect, int $delaySeconds): bool
    {
        $step = $campaign->stepAfter($prospect->current_step);

        if ($step === null) {
            $this->claim($prospect) && $prospect->finish(SequenceStatus::Completed, 'Every step was sent.');

            return false;
        }

        $contact = $prospect->contact;

        if ($contact->hasRepliedSince($prospect->created_at)) {
            $prospect->finish(SequenceStatus::Replied, 'Replied');

            return false;
        }

        $blockReason = $this->eligibility->blockReason($contact->email, $campaign->purpose);

        if ($blockReason !== null) {
            $prospect->finish(SequenceStatus::Stopped, "Not eligible any more: {$blockReason}");

            return false;
        }

        if (! $step->condition->allows($this->wasOpened($prospect))) {
            $this->skip($campaign, $prospect, $step);

            return false;
        }

        $window = $campaign->sendingWindowFor($contact);

        // Due while the window is closed (it may have changed since the due time was set): wait for it to open.
        if (! $window->contains(now())) {
            CampaignProspect::query()->whereKey($prospect->getKey())->active()->update(['next_step_due_at' => $window->nextOpening(now())]);

            return false;
        }

        $variant = app(VariantPicker::class)->pick($step->variants, $campaign->getKey(), $contact->getKey(), $step->stepNumber());

        $firstSubject = EmailMessage::query()->where('campaign_id', $campaign->getKey())->where('contact_id', $contact->getKey())->where('step_number', 1)->value('subject');

        $message = DB::transaction(function () use ($campaign, $prospect, $step, $contact, $variant, $firstSubject): ?EmailMessage {
            if (! $this->claim($prospect)) {
                return null;
            }

            try {
                $message = EmailMessage::query()->create([
                    'campaign_id' => $campaign->getKey(),
                    'contact_id' => $contact->getKey(),
                    'sender_identity_id' => $campaign->sender_identity_id,
                    'created_by' => $campaign->created_by,
                    'purpose' => $campaign->purpose,
                    'source' => MessageSource::Campaign,
                    'status' => MessageStatus::Queued,
                    'to_email' => $contact->email,
                    'to_name' => $contact->full_name,
                    'campaign_step_id' => $step->getKey(),
                    'step_number' => $step->stepNumber(),
                    'campaign_variant_id' => $variant?->getKey(),
                    'subject' => $step->subjectFor($campaign, $variant, $firstSubject),
                    'queued_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return null;
            }

            $message->recordEvent(EmailEventType::Queued, details: ['step' => $step->stepNumber()]);
            $prospect->log('queued', $step->stepNumber());

            return $message;
        });

        if ($message === null) {
            return false;
        }

        SendEmailMessage::dispatch($message)->delay(now()->addSeconds($delaySeconds));

        return true;
    }

    /**
     * The step's condition was not met: move past it, to the next follow-up's wait or the end.
     */
    private function skip(Campaign $campaign, CampaignProspect $prospect, CampaignStep $step): void
    {
        if (! $this->claim($prospect)) {
            return;
        }

        $prospect->log('skipped', $step->stepNumber(), $step->condition->getLabel().': not met');
        $next = $campaign->stepAfter($step->stepNumber());

        if ($next === null) {
            $prospect->scheduleNext($step->stepNumber(), null);
            $prospect->finish(SequenceStatus::Completed, 'The last step was skipped.');

            return;
        }

        $prospect->scheduleNext(
            $step->stepNumber(),
            $campaign->sendingWindowFor($prospect->contact)->nextOpening(now()->addDays($next->wait_days)),
        );
    }

    /**
     * Take the prospect's due follow-up, unless another run or a stop took it first.
     */
    private function claim(CampaignProspect $prospect): bool
    {
        return CampaignProspect::query()
            ->whereKey($prospect->getKey())
            ->where('status', SequenceStatus::Active)
            ->where('next_step_due_at', $prospect->next_step_due_at)
            ->update(['next_step_due_at' => null, 'updated_at' => now()]) === 1;
    }

    private function wasOpened(CampaignProspect $prospect): bool
    {
        return EmailEvent::query()
            ->whereIn('email_message_id', $prospect->messages()->select('email_messages.id'))
            ->where('type', EmailEventType::Opened)
            ->exists();
    }
}
