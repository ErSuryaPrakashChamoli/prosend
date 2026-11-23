<?php

namespace App\Services\Campaigns;

use App\Enums\SequenceStatus;
use App\Enums\SuppressionReason;
use App\Models\CampaignProspect;
use App\Models\Contact;
use App\Models\EmailMessage;

/**
 * Moves prospects through their sequence as their emails are sent, and ends sequences when a prospect replies,
 * unsubscribes, bounces, complains or is suppressed. Every update is conditional on the prospect still being active, so
 * a stop always wins over a step that was in flight.
 */
class SequenceProgress
{
    /**
     * A step's email reached the provider (or a sandbox): schedule the next follow-up, or finish.
     */
    public function stepSent(EmailMessage $message): void
    {
        $prospect = $this->prospectFor($message);

        if ($prospect === null || ! $prospect->isActive() || $message->step_number <= $prospect->current_step) {
            return;
        }

        $campaign = $message->campaign;
        $next = $campaign->stepAfter($message->step_number);

        $prospect->forceFill(['last_sent_at' => now()])->save();
        $prospect->log('sent', $message->step_number);

        if ($next === null) {
            $prospect->scheduleNext($message->step_number, null);
            $prospect->finish(SequenceStatus::Completed, 'Every step was sent.');

            return;
        }

        $dueAt = $campaign->sendingWindowFor($prospect->contact)->nextOpening(now()->addDays($next->wait_days));

        $prospect->scheduleNext($message->step_number, $dueAt);
    }

    /**
     * A step's email will never be sent, so the rest of the sequence would make no sense.
     */
    public function stepFailed(EmailMessage $message, SequenceStatus $status, string $reason): void
    {
        $this->prospectFor($message)?->finish($status, "Step {$message->step_number}: {$reason}");
    }

    /**
     * A retried email continues the sequence it failed in.
     */
    public function reactivateForRetry(EmailMessage $message): void
    {
        $prospect = $this->prospectFor($message);

        if ($prospect === null || $prospect->status !== SequenceStatus::Failed) {
            return;
        }

        $prospect->forceFill(['status' => SequenceStatus::Active, 'finished_at' => null, 'finish_reason' => null])->save();
        $prospect->log('reactivated', $message->step_number, 'Email retried');
    }

    /**
     * End every active sequence for the contact, in all of its client's campaigns.
     */
    public function stopForContact(Contact $contact, SequenceStatus $status, string $reason): int
    {
        return CampaignProspect::acrossClients()
            ->where('client_id', $contact->client_id)
            ->where('contact_id', $contact->getKey())
            ->active()
            ->get()
            ->filter(fn (CampaignProspect $prospect): bool => $prospect->finish($status, $reason))
            ->count();
    }

    /**
     * A suppressed address gets no more follow-ups from the client that suppressed it.
     */
    public function stopForSuppression(int $clientId, string $email, SuppressionReason $reason): int
    {
        $contact = Contact::acrossClients()->where('client_id', $clientId)->where('email', $email)->first();

        if ($contact === null) {
            return 0;
        }

        $status = match ($reason) {
            SuppressionReason::HardBounce, SuppressionReason::InvalidAddress => SequenceStatus::Bounced,
            SuppressionReason::Complaint, SuppressionReason::Unsubscribed => SequenceStatus::Unsubscribed,
            SuppressionReason::Manual => SequenceStatus::Stopped,
        };

        return $this->stopForContact($contact, $status, "Suppressed: {$reason->getLabel()}");
    }

    private function prospectFor(EmailMessage $message): ?CampaignProspect
    {
        if ($message->campaign_id === null || $message->contact_id === null) {
            return null;
        }

        return CampaignProspect::acrossClients()
            ->where('campaign_id', $message->campaign_id)
            ->where('contact_id', $message->contact_id)
            ->first();
    }
}
