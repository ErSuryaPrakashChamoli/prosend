<?php

namespace App\Policies;

use App\Models\SenderIdentity;
use App\Models\User;

class SenderIdentityPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->canManageInfrastructure();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SenderIdentity $senderIdentity): bool
    {
        return $user->role->canManageInfrastructure();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role->canManageInfrastructure();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SenderIdentity $senderIdentity): bool
    {
        return $user->role->canManageInfrastructure();
    }

    /**
     * Identities used by campaigns or sent messages are kept so the sending history stays complete.
     */
    public function delete(User $user, SenderIdentity $senderIdentity): bool
    {
        return $user->role->canManageInfrastructure()
            && ! $senderIdentity->campaigns()->exists()
            && ! $senderIdentity->emailMessages()->exists();
    }
}
