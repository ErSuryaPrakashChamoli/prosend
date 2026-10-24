<?php

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\User;

class EmailTemplatePolicy
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
    public function view(User $user, EmailTemplate $emailTemplate): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role->canSendEmail();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, EmailTemplate $emailTemplate): bool
    {
        return $user->role->canSendEmail();
    }

    /**
     * Templates that campaigns or sent email used are archived instead, so their history stays readable.
     */
    public function delete(User $user, EmailTemplate $emailTemplate): bool
    {
        return $user->role->canSendEmail()
            && ! $emailTemplate->campaigns()->exists()
            && ! $emailTemplate->hasSentEmail();
    }
}
