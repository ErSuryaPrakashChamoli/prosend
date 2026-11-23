<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ApiKey;
use App\Models\User;

class ApiKeyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageIntegrations);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ApiKey $apiKey): bool
    {
        return $user->hasPermission(Permission::ManageIntegrations, $apiKey->client_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageIntegrations);
    }

    /**
     * Revoked keys are kept for the audit trail and cannot be edited.
     */
    public function update(User $user, ApiKey $apiKey): bool
    {
        return $user->hasPermission(Permission::ManageIntegrations, $apiKey->client_id) && ! $apiKey->isRevoked();
    }

    /**
     * Keys are revoked, never deleted, so their usage history stays readable.
     */
    public function delete(User $user, ApiKey $apiKey): bool
    {
        return false;
    }
}
