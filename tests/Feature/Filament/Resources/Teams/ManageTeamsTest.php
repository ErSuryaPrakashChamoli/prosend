<?php

use App\Enums\SystemRole;
use App\Filament\Resources\Teams\Pages\ManageTeams;
use App\Models\Client;
use App\Models\Team;
use App\Models\User;
use Database\Factories\UserFactory;
use Livewire\Livewire;

it('creates a team from the client\'s members', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());
    $leader = User::factory()->sender()->create();
    $rep = User::factory()->create();

    Livewire::test(ManageTeams::class)
        ->callAction('create', data: ['name' => 'North pod', 'leader_id' => $leader->id, 'members' => [$leader->id, $rep->id]])
        ->assertHasNoActionErrors();

    $team = Team::query()->sole();
    expect($team->leader_id)->toBe($leader->id)
        ->and($team->members()->pluck('users.id')->sort()->values()->all())->toBe(collect([$leader->id, $rep->id])->sort()->values()->all());
});

it('does not offer people from other clients as leaders', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());
    $outsider = User::factory()->withoutClient()->create();
    UserFactory::addToClient($outsider, Client::factory()->create(), SystemRole::Admin);

    Livewire::test(ManageTeams::class)
        ->callAction('create', data: ['name' => 'North pod', 'leader_id' => $outsider->id])
        ->assertHasActionErrors(['leader_id']);
});

it('allows the same team name in two clients, but not twice in one', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());
    Team::factory()->create(['name' => 'North pod']);
    asClient(Client::factory()->create(), fn () => Team::factory()->create(['name' => 'South pod']));

    Livewire::test(ManageTeams::class)
        ->callAction('create', data: ['name' => 'North pod'])
        ->assertHasActionErrors(['name']);

    Livewire::test(ManageTeams::class)
        ->callAction('create', data: ['name' => 'South pod'])
        ->assertHasNoActionErrors();
});

it('lets members see teams but only team managers change them', function () {
    $this->actingAs($viewer = User::factory()->create());

    expect($viewer->can('viewAny', Team::class))->toBeTrue()
        ->and($viewer->can('create', Team::class))->toBeFalse();
});
