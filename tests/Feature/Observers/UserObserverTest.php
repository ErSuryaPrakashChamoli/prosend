<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;

it('audits a role change with the old and new role', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    $user->update(['role' => UserRole::Admin]);

    expect(AuditLog::query()->where('action', 'user.role_changed')->sole()->properties)->toBe([
        'email' => 'sam@example.com',
        'from' => 'viewer',
        'to' => 'admin',
    ]);
});
