<?php

namespace App\Policies;

use App\Models\Suppression;
use App\Models\User;

class SuppressionPolicy
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
    public function view(User $user, Suppression $suppression): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role->canManageAudiences();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Suppression $suppression): bool
    {
        return $user->role->canManageAudiences();
    }

    /**
     * Removing a suppression allows sending to an address that bounced or complained, so only administrators may.
     */
    public function delete(User $user, Suppression $suppression): bool
    {
        return $user->role->canManageInfrastructure();
    }

    /**
     * Determine whether the user can delete models in bulk.
     */
    public function deleteAny(User $user): bool
    {
        return $user->role->canManageInfrastructure();
    }
}
