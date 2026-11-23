<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\EmailTemplate;
use App\Models\User;

class EmailTemplatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewCampaigns);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, EmailTemplate $emailTemplate): bool
    {
        return $user->hasPermission(Permission::ViewCampaigns, $emailTemplate->client_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTemplates);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, EmailTemplate $emailTemplate): bool
    {
        return $user->hasPermission(Permission::ManageTemplates, $emailTemplate->client_id);
    }

    /**
     * Templates that campaigns or sent email used are archived instead, so their history stays readable.
     */
    public function delete(User $user, EmailTemplate $emailTemplate): bool
    {
        return $user->hasPermission(Permission::ManageTemplates, $emailTemplate->client_id)
            && ! $emailTemplate->campaigns()->exists()
            && ! $emailTemplate->hasSentEmail();
    }
}
