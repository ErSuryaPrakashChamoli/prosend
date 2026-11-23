<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\EmailMessage;
use App\Models\User;

class EmailMessagePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewActivity);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, EmailMessage $emailMessage): bool
    {
        return $user->hasPermission(Permission::ViewActivity, $emailMessage->client_id);
    }

    /**
     * Determine whether the user can compose and send email.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ComposeEmail);
    }

    /**
     * Determine whether the user can retry a failed message.
     */
    public function retry(User $user, EmailMessage $emailMessage): bool
    {
        return $user->hasPermission(Permission::ComposeEmail, $emailMessage->client_id)
            || $user->hasPermission(Permission::OperateCampaigns, $emailMessage->client_id);
    }
}
