<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignApprovalNotifier;
use Illuminate\Validation\ValidationException;

/**
 * Sends a campaign waiting for approval back to draft: an approver rejecting it, or its submitter withdrawing it.
 */
class ReturnCampaignToDraft
{
    public function __construct(
        private CampaignApprovalNotifier $notifier,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Campaign $campaign, User $user, ?string $note = null): void
    {
        if ($campaign->status !== CampaignStatus::PendingApproval || $campaign->started_at !== null) {
            throw ValidationException::withMessages(['campaign' => 'This campaign is no longer waiting for approval.']);
        }

        if (! $user->can('returnToDraft', $campaign)) {
            throw ValidationException::withMessages(['campaign' => 'Only an administrator or the person who submitted it can return this campaign to draft.']);
        }

        $note = filled($note) ? trim($note) : null;

        $returned = $campaign->transitionTo(CampaignStatus::Draft, from: [CampaignStatus::PendingApproval], attributes: [
            'submitted_at' => null,
            'review_note' => $note,
        ], onlyBeforeSending: true);

        if (! $returned) {
            throw ValidationException::withMessages(['campaign' => 'This campaign is no longer waiting for approval.']);
        }

        $this->auditLogger->record('campaign.returned_to_draft', $campaign, ['note' => $note], $user);

        $this->notifier->notifySubmitter(
            $campaign,
            "Campaign returned to draft: {$campaign->name}",
            $note === null ? "{$user->name} returned it to draft." : "{$user->name} returned it to draft: {$note}",
            except: $user,
        );
    }
}
