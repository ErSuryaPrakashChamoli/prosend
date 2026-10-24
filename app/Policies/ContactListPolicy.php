<?php

namespace App\Policies;

use App\Models\ContactList;
use App\Models\User;

class ContactListPolicy
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
    public function view(User $user, ContactList $contactList): bool
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
    public function update(User $user, ContactList $contactList): bool
    {
        return $user->role->canManageAudiences();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ContactList $contactList): bool
    {
        return $user->role->canManageAudiences();
    }

    /**
     * Determine whether the user can delete models in bulk.
     */
    public function deleteAny(User $user): bool
    {
        return $user->role->canManageAudiences();
    }
}
