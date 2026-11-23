<?php

use App\Enums\SystemRole;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

it('creates a member with access to chosen clients', function () {
    $other = Client::factory()->create();

    Livewire::test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Asha Rao',
            'email' => 'asha@example.com',
            'role' => UserRole::Viewer->value,
            'password' => 'a-Long-password-1234',
            'memberships' => [
                ['client_id' => $other->id, 'role_id' => Role::system(SystemRole::TeamLeader)->id],
            ],
        ])
        ->assertHasNoActionErrors();

    $asha = User::query()->where('email', 'asha@example.com')->sole();
    expect($asha->isSuperAdmin())->toBeFalse()
        ->and($asha->roleIn($other)?->key)->toBe(SystemRole::TeamLeader->value)
        ->and($asha->roleIn(Client::query()->where('slug', 'default')->sole()))->toBeNull();
});

it('makes someone a Super Admin', function () {
    $sam = User::factory()->create();

    Livewire::test(ManageUsers::class)
        ->callAction(TestAction::make('edit')->table($sam), data: ['role' => UserRole::Admin->value])
        ->assertHasNoActionErrors();

    expect($sam->refresh()->isSuperAdmin())->toBeTrue();
});

it('keeps the users page for Super Admins', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());

    Livewire::test(ManageUsers::class)->assertForbidden();
});
