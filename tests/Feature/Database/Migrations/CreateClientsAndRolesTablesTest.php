<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('creates the built-in roles with their default permissions', function () {
    expect(Role::query()->whereNotNull('key')->orderBy('id')->pluck('key')->all())->toBe(array_map(fn (SystemRole $role): string => $role->value, SystemRole::cases()))
        ->and(Role::system(SystemRole::Admin)->permissions)->toBe(Permission::values())
        ->and(Role::system(SystemRole::GuestViewer)->permissions)->toBe(array_map(fn (Permission $permission): string => $permission->value, SystemRole::GuestViewer->defaultPermissions()));
});

it('gives existing senders and viewers the closest role in the Default client, and makes administrators Super Admins', function () {
    $migration = require database_path('migrations/2026_11_23_065224_create_clients_and_roles_tables.php');
    DB::table('client_user')->delete();
    DB::table('clients')->where('slug', 'default')->update(['slug' => 'previous-default']);
    $sender = User::factory()->withoutClient()->create(['role' => UserRole::Sender]);
    $viewer = User::factory()->withoutClient()->create(['role' => UserRole::Viewer]);
    $admin = User::factory()->withoutClient()->admin()->create();

    $migration->createDefaultClientWithMembers();

    $client = Client::query()->where('slug', 'default')->sole();
    expect($sender->roleIn($client)?->key)->toBe(SystemRole::CampaignManager->value)
        ->and($viewer->roleIn($client)?->key)->toBe(SystemRole::GuestViewer->value)
        ->and($admin->memberships()->exists())->toBeFalse()
        ->and($admin->isSuperAdmin())->toBeTrue();
});

it('assigns every existing row to the Default client', function () {
    $tables = (require database_path('migrations/2026_11_23_065225_add_client_id_to_client_owned_tables.php'))::CLIENT_OWNED_TABLES;
    $defaultClientId = Client::query()->where('slug', 'default')->value('id');

    foreach ($tables as $table) {
        $clientId = collect(DB::getSchemaBuilder()->getColumns($table))->firstWhere('name', 'client_id');

        expect($clientId)->not->toBeNull()
            ->and($clientId['nullable'])->toBeFalse();
    }

    expect($defaultClientId)->not->toBeNull();
});
