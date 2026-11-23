<?php

namespace App\Policies;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role->canSendEmail();
    }

    /**
     * Only drafts can be changed; a launched campaign's content is a record of what was sent.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        return $user->role->canSendEmail() && $campaign->status->canBeEdited() && $campaign->started_at === null;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->role->canSendEmail() && $campaign->status->canBeEdited() && $campaign->started_at === null;
    }

    /**
     * Determine whether the user can pause, resume, cancel, or retry the campaign, or send a test.
     */
    public function operate(User $user, Campaign $campaign): bool
    {
        return $user->role->canSendEmail();
    }

    /**
     * Anyone who can send email may submit a draft for approval.
     */
    public function submit(User $user, Campaign $campaign): bool
    {
        return $user->role->canSendEmail() && $campaign->status === CampaignStatus::Draft;
    }

    /**
     * Administrators approve campaigns. When a second approver is required, nobody approves a campaign they created,
     * last edited, or submitted.
     */
    public function approve(User $user, Campaign $campaign): bool
    {
        if (! $user->role->canApproveCampaigns() || $campaign->status !== CampaignStatus::PendingApproval) {
            return false;
        }

        if (! config('prosend.campaigns.approval.require_second_approver')) {
            return true;
        }

        return ! in_array($user->getKey(), [$campaign->created_by, $campaign->updated_by, $campaign->submitted_by], true);
    }

    /**
     * An approver can send a campaign back, and its submitter can withdraw it.
     */
    public function returnToDraft(User $user, Campaign $campaign): bool
    {
        return $campaign->status === CampaignStatus::PendingApproval
            && $user->role->canSendEmail()
            && ($user->role->canApproveCampaigns() || $campaign->submitted_by === $user->getKey());
    }
}
