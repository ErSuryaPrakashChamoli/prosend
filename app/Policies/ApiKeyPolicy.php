<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\User;

class ApiKeyPolicy
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
    public function view(User $user, ApiKey $apiKey): bool
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
     * Revoked keys are kept for the audit trail and cannot be edited.
     */
    public function update(User $user, ApiKey $apiKey): bool
    {
        return $user->role->canManageInfrastructure() && ! $apiKey->isRevoked();
    }

    /**
     * Keys are revoked, never deleted, so their usage history stays readable.
     */
    public function delete(User $user, ApiKey $apiKey): bool
    {
        return false;
    }
}
