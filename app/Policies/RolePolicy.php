<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Roles are shared by every client, so only Super Admins define them.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Role $role): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * The Admin role's permissions are fixed, but it can be renamed and described.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Built-in roles and roles still given to people are kept.
     */
    public function delete(User $user, Role $role): bool
    {
        return $user->isSuperAdmin() && ! $role->isSystem() && ! $role->memberships()->exists();
    }
}
