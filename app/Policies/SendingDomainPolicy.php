<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SendingDomain;
use App\Models\User;

class SendingDomainPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageDeliverability);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SendingDomain $sendingDomain): bool
    {
        return $user->hasPermission(Permission::ManageDeliverability, $sendingDomain->client_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageDeliverability);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SendingDomain $sendingDomain): bool
    {
        return $user->hasPermission(Permission::ManageDeliverability, $sendingDomain->client_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SendingDomain $sendingDomain): bool
    {
        return $user->hasPermission(Permission::ManageDeliverability, $sendingDomain->client_id);
    }
}
