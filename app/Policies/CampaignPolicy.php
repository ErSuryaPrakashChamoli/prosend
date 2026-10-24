<?php

namespace App\Policies;

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
        return $user->role->canSendEmail() && $campaign->status->canBeEdited();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->role->canSendEmail() && $campaign->status->canBeEdited();
    }

    /**
     * Determine whether the user can launch, pause, resume, cancel, or retry the campaign.
     */
    public function operate(User $user, Campaign $campaign): bool
    {
        return $user->role->canSendEmail();
    }
}
