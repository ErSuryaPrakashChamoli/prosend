<?php

namespace App\Policies;

use App\Models\Segment;
use App\Models\User;

class SegmentPolicy
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
    public function view(User $user, Segment $segment): bool
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
    public function update(User $user, Segment $segment): bool
    {
        return $user->role->canManageAudiences();
    }

    /**
     * Segments used by campaigns are kept so those campaigns still show their audience.
     */
    public function delete(User $user, Segment $segment): bool
    {
        return $user->role->canManageAudiences() && ! $segment->campaigns()->exists();
    }
}
