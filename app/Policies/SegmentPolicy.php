<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Segment;
use App\Models\User;

class SegmentPolicy
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
    public function view(User $user, Segment $segment): bool
    {
        return $user->hasPermission(Permission::ViewProspects, $segment->client_id);
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
    public function update(User $user, Segment $segment): bool
    {
        return $user->hasPermission(Permission::ManageProspects, $segment->client_id);
    }

    /**
     * Segments used by campaigns are kept so those campaigns still show their audience.
     */
    public function delete(User $user, Segment $segment): bool
    {
        return $user->hasPermission(Permission::ManageProspects, $segment->client_id) && ! $segment->campaigns()->exists();
    }
}
