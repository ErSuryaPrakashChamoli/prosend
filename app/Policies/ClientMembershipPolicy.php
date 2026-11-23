<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientMembership;
use App\Models\User;

/**
 * Client admins invite people and change their roles. Nobody changes or removes their own membership, so a client
 * cannot be left without the person managing it by accident.
 */
class ClientMembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTeam);
    }

    public function view(User $user, ClientMembership $membership): bool
    {
        return $user->hasPermission(Permission::ManageTeam, $membership->client_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTeam);
    }

    public function update(User $user, ClientMembership $membership): bool
    {
        return $this->canManage($user, $membership);
    }

    public function delete(User $user, ClientMembership $membership): bool
    {
        return $this->canManage($user, $membership);
    }

    /**
     * Someone with team management may change or remove members whose role they could have given, never their own
     * membership or someone more powerful than themselves.
     */
    private function canManage(User $user, ClientMembership $membership): bool
    {
        return $user->hasPermission(Permission::ManageTeam, $membership->client_id)
            && $membership->user_id !== $user->getKey()
            && $user->canAssignRole($membership->role, $membership->client_id);
    }
}
