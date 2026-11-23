<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ContactList;
use App\Models\User;

class ContactListPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewProspects);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ContactList $contactList): bool
    {
        return $user->hasPermission(Permission::ViewProspects, $contactList->client_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageProspects);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ContactList $contactList): bool
    {
        return $user->hasPermission(Permission::ManageProspects, $contactList->client_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ContactList $contactList): bool
    {
        return $user->hasPermission(Permission::ManageProspects, $contactList->client_id);
    }

    /**
     * Determine whether the user can delete models in bulk.
     */
    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageProspects);
    }
}
