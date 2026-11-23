<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewAuditLog);
    }

    /**
     * Entries about the agency itself belong to no client and are for Super Admins only.
     */
    public function view(User $user, AuditLog $auditLog): bool
    {
        return $auditLog->client_id === null
            ? $user->isSuperAdmin()
            : $user->hasPermission(Permission::ViewAuditLog, $auditLog->client_id);
    }
}
