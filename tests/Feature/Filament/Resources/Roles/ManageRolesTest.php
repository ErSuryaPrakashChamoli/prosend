<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Scopes\ClientScope;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

it('creates a custom role with chosen permissions', function () {
    Livewire::test(ManageRoles::class)
        ->callAction('create', data: [
            'name' => 'Researcher',
            'permissions' => [Permission::ViewProspects->value, Permission::ImportProspects->value],
        ])
        ->assertHasNoActionErrors();

    expect(Role::query()->where('name', 'Researcher')->sole())
        ->key->toBeNull()
        ->permissions->toBe([Permission::ViewProspects->value, Permission::ImportProspects->value]);
});

it('changes a built-in role\'s permissions and audits what changed', function () {
    $analyst = Role::system(SystemRole::Analyst);

    Livewire::test(ManageRoles::class)
        ->callAction(TestAction::make('edit')->table($analyst), data: [
            'permissions' => [...$analyst->permissions, Permission::ImportProspects->value],
        ])
        ->assertHasNoActionErrors();

    expect($analyst->refresh()->grants(Permission::ImportProspects))->toBeTrue();
    expect(AuditLog::query()->withoutGlobalScope(ClientScope::class)->where('action', 'role.permissions_changed')->sole())
        ->client_id->toBeNull()
        ->properties->toMatchArray(['added' => [Permission::ImportProspects->value], 'removed' => []]);
});

it('offers delete only for unused custom roles', function () {
    $unused = Role::factory()->create();

    Livewire::test(ManageRoles::class)
        ->assertActionHidden(TestAction::make('delete')->table(Role::system(SystemRole::Analyst)))
        ->callAction(TestAction::make('delete')->table($unused));

    expect(Role::query()->whereKey($unused->id)->exists())->toBeFalse();
});

it('keeps roles for Super Admins only', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());

    Livewire::test(ManageRoles::class)->assertForbidden();
});
