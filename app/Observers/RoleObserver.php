<?php

namespace App\Observers;

use App\Models\Role;
use App\Services\AuditLogger;

class RoleObserver
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function updated(Role $role): void
    {
        if ($role->wasChanged('permissions')) {
            $this->auditLogger->record('role.permissions_changed', $role, [
                'name' => $role->name,
                'added' => array_values(array_diff($role->permissions, (array) json_decode((string) $role->getRawOriginal('permissions'), true))),
                'removed' => array_values(array_diff((array) json_decode((string) $role->getRawOriginal('permissions'), true), $role->permissions)),
            ]);
        }
    }

    public function deleted(Role $role): void
    {
        $this->auditLogger->record('role.deleted', $role, ['name' => $role->name]);
    }
}
