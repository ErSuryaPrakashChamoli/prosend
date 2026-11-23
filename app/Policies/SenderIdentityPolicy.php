<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SenderIdentity;
use App\Models\User;

class SenderIdentityPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SenderIdentity $senderIdentity): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes, $senderIdentity->client_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SenderIdentity $senderIdentity): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes, $senderIdentity->client_id);
    }

    /**
     * Identities used by campaigns or sent messages are kept so the sending history stays complete.
     */
    public function delete(User $user, SenderIdentity $senderIdentity): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes, $senderIdentity->client_id)
            && ! $senderIdentity->campaigns()->exists()
            && ! $senderIdentity->emailMessages()->exists();
    }
}
