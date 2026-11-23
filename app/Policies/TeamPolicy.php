<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Everyone who can see the client's prospects can see its teams.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewProspects);
    }

    public function view(User $user, Team $team): bool
    {
        return $user->hasPermission(Permission::ViewProspects, $team->client_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTeam);
    }

    public function update(User $user, Team $team): bool
    {
        return $user->hasPermission(Permission::ManageTeam, $team->client_id);
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->hasPermission(Permission::ManageTeam, $team->client_id);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTeam);
    }
}
