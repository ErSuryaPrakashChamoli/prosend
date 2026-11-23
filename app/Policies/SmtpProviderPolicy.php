<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SmtpProvider;
use App\Models\User;

class SmtpProviderPolicy
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
    public function view(User $user, SmtpProvider $smtpProvider): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes, $smtpProvider->client_id);
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
    public function update(User $user, SmtpProvider $smtpProvider): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes, $smtpProvider->client_id);
    }

    /**
     * Providers with sender identities or sending history are disabled instead of deleted.
     */
    public function delete(User $user, SmtpProvider $smtpProvider): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes, $smtpProvider->client_id)
            && ! $smtpProvider->senderIdentities()->exists()
            && ! $smtpProvider->emailMessages()->exists();
    }
}
