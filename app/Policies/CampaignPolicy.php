<?php

namespace App\Policies;

use App\Enums\CampaignStatus;
use App\Enums\Permission;
use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewCampaigns);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::ViewCampaigns, $campaign->client_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageCampaigns);
    }

    /**
     * Only drafts can be changed; a launched campaign's content is a record of what was sent.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::ManageCampaigns, $campaign->client_id) && $campaign->status->canBeEdited() && $campaign->started_at === null;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::ManageCampaigns, $campaign->client_id) && $campaign->status->canBeEdited() && $campaign->started_at === null;
    }

    /**
     * Determine whether the user can pause, resume, cancel, or retry the campaign, or send a test.
     */
    public function operate(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::OperateCampaigns, $campaign->client_id);
    }

    /**
     * Anyone who manages campaigns may submit a draft for approval.
     */
    public function submit(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::ManageCampaigns, $campaign->client_id) && $campaign->status === CampaignStatus::Draft;
    }

    /**
     * People with the approve permission approve campaigns. When a second approver is required, nobody approves a campaign they created,
     * last edited, or submitted.
     */
    public function approve(User $user, Campaign $campaign): bool
    {
        if (! $user->hasPermission(Permission::ApproveCampaigns, $campaign->client_id) || $campaign->status !== CampaignStatus::PendingApproval) {
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
            && ($user->hasPermission(Permission::ApproveCampaigns, $campaign->client_id)
                || ($user->hasPermission(Permission::ManageCampaigns, $campaign->client_id) && $campaign->submitted_by === $user->getKey()));
    }

    /**
     * Drafts and finished campaigns can be archived and restored by people who manage campaigns.
     */
    public function archive(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::ManageCampaigns, $campaign->client_id)
            && ($campaign->isArchived() || $campaign->canBeArchived());
    }
}
