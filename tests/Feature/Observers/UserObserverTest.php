<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Scopes\ClientScope;
use App\Models\User;

it('audits a role change with the old and new role', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    $user->update(['role' => UserRole::Admin]);

    // People span clients, so the entry belongs to the agency rather than to a client.
    $entry = AuditLog::query()->withoutGlobalScope(ClientScope::class)->where('action', 'user.role_changed')->sole();

    expect($entry->client_id)->toBeNull()
        ->and($entry->properties)->toBe([
            'email' => 'sam@example.com',
            'from' => 'viewer',
            'to' => 'admin',
        ]);
});
