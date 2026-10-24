<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AuditLogger;

class UserObserver
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function created(User $user): void
    {
        $this->auditLogger->record('user.created', $user, ['email' => $user->email, 'role' => $user->role->value]);
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('role')) {
            $this->auditLogger->record('user.role_changed', $user, [
                'email' => $user->email,
                'from' => $user->getOriginal('role')?->value,
                'to' => $user->role->value,
            ]);
        }

        if ($user->wasChanged('password')) {
            $this->auditLogger->record('user.password_changed', $user, ['email' => $user->email]);
        }
    }

    public function deleted(User $user): void
    {
        $this->auditLogger->record('user.deleted', $user, ['email' => $user->email]);
    }
}
