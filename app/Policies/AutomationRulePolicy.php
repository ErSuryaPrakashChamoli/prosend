<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AutomationRule;
use App\Models\User;

class AutomationRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageAutomations);
    }

    public function view(User $user, AutomationRule $rule): bool
    {
        return $user->hasPermission(Permission::ManageAutomations, $rule->client_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageAutomations);
    }

    public function update(User $user, AutomationRule $rule): bool
    {
        return $user->hasPermission(Permission::ManageAutomations, $rule->client_id);
    }

    public function delete(User $user, AutomationRule $rule): bool
    {
        return $user->hasPermission(Permission::ManageAutomations, $rule->client_id);
    }
}
