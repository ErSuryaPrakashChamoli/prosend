<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\InboundMailbox;
use App\Models\User;

class InboundMailboxPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes);
    }

    public function view(User $user, InboundMailbox $mailbox): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes, $mailbox->client_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes);
    }

    public function update(User $user, InboundMailbox $mailbox): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes, $mailbox->client_id);
    }

    /**
     * A mailbox whose replies are in the inbox is disabled instead, so those conversations keep their source.
     */
    public function delete(User $user, InboundMailbox $mailbox): bool
    {
        return $user->hasPermission(Permission::ManageMailboxes, $mailbox->client_id) && ! $mailbox->conversations()->exists();
    }
}
