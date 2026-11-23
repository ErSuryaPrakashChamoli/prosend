<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

/**
 * Clients are the agency's own records: only Super Admins create, change or deactivate them.
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Client $client): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Client $client): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Clients are deactivated, never deleted, so their sending history is kept.
     */
    public function delete(User $user, Client $client): bool
    {
        return false;
    }
}
