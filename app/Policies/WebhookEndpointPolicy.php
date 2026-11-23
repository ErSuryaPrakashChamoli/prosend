<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WebhookEndpoint;

class WebhookEndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageIntegrations);
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->hasPermission(Permission::ManageIntegrations, $endpoint->client_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageIntegrations);
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->hasPermission(Permission::ManageIntegrations, $endpoint->client_id);
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->hasPermission(Permission::ManageIntegrations, $endpoint->client_id);
    }
}
