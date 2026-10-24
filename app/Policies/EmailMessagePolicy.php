<?php

namespace App\Policies;

use App\Models\EmailMessage;
use App\Models\User;

class EmailMessagePolicy
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
    public function view(User $user, EmailMessage $emailMessage): bool
    {
        return true;
    }

    /**
     * Determine whether the user can compose and send email.
     */
    public function create(User $user): bool
    {
        return $user->role->canSendEmail();
    }

    /**
     * Determine whether the user can retry a failed message.
     */
    public function retry(User $user, EmailMessage $emailMessage): bool
    {
        return $user->role->canSendEmail();
    }
}
