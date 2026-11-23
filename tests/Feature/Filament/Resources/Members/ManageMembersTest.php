<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Filament\Resources\Members\Pages\ManageMembers;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ClientInvitation;
use App\Services\Tenancy\CurrentClient;
use Database\Factories\UserFactory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->client = app(CurrentClient::class)->get();
    $this->actingAs($this->admin = User::factory()->withClientRole(SystemRole::Admin)->create());
});

it('invites someone new, with a link to choose their password', function () {
    Livewire::test(ManageMembers::class)
        ->callAction('invite', data: [
            'email' => 'Priya@Example.com',
            'name' => 'Priya Shah',
            'role_id' => Role::system(SystemRole::SalesExecutive)->id,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $priya = User::query()->where('email', 'priya@example.com')->sole();
    expect($priya->isSuperAdmin())->toBeFalse()
        ->and($priya->roleIn($this->client)?->key)->toBe(SystemRole::SalesExecutive->value);
    Notification::assertSentTo($priya, ClientInvitation::class, fn (ClientInvitation $invitation): bool => $invitation->passwordToken !== null
        && $invitation->client->is($this->client)
        && str_contains($invitation->toMail($priya)->actionUrl, 'password-reset'));
    expect(AuditLog::query()->where('action', 'member.added')->where('properties->email', 'priya@example.com')->sole()->properties)
        ->toBe(['email' => 'priya@example.com', 'role' => 'Sales Executive']);
});

it('adds an existing account without touching its password', function () {
    $other = Client::factory()->create();
    $existing = User::factory()->withoutClient()->create(['email' => 'sam@example.com']);
    UserFactory::addToClient($existing, $other, SystemRole::Analyst);
    $passwordHash = $existing->password;

    Livewire::test(ManageMembers::class)
        ->callAction('invite', data: ['email' => 'sam@example.com', 'name' => 'Ignored', 'role_id' => Role::system(SystemRole::Analyst)->id])
        ->assertHasNoActionErrors();

    expect($existing->refresh())
        ->password->toBe($passwordHash)
        ->name->not->toBe('Ignored');
    expect($existing->refreshRoles()->roleIn($this->client))->not->toBeNull();
    Notification::assertSentTo($existing, ClientInvitation::class, fn (ClientInvitation $invitation): bool => $invitation->passwordToken === null);
});

it('refuses to invite a Super Admin or an existing member', function () {
    User::factory()->admin()->create(['email' => 'boss@example.com']);
    User::factory()->create(['email' => 'member@example.com']);

    Livewire::test(ManageMembers::class)
        ->callAction('invite', data: ['email' => 'boss@example.com', 'name' => 'Boss', 'role_id' => Role::system(SystemRole::Analyst)->id])
        ->assertHasActionErrors(['email']);

    Livewire::test(ManageMembers::class)
        ->callAction('invite', data: ['email' => 'member@example.com', 'name' => 'Member', 'role_id' => Role::system(SystemRole::Analyst)->id])
        ->assertHasActionErrors(['email']);

    Notification::assertNothingSent();
});

it('lists only this client\'s members', function () {
    $colleague = User::factory()->create();
    $outsider = User::factory()->withoutClient()->create();
    $otherClient = Client::factory()->create();
    $otherMembership = UserFactory::addToClient($outsider, $otherClient, SystemRole::Admin);

    Livewire::test(ManageMembers::class)
        ->assertCanSeeTableRecords(ClientMembership::query()->where('user_id', $colleague->id)->get())
        ->assertCanNotSeeTableRecords([$otherMembership]);
});

it('changes a member\'s role and removes members, but never the admin\'s own membership', function () {
    $colleague = User::factory()->create();
    $membership = ClientMembership::query()->where('user_id', $colleague->id)->sole();
    $own = ClientMembership::query()->where('user_id', $this->admin->id)->sole();

    Livewire::test(ManageMembers::class)
        ->callAction(TestAction::make('edit')->table($membership), data: ['role_id' => Role::system(SystemRole::TeamLeader)->id])
        ->assertHasNoActionErrors()
        ->assertActionHidden(TestAction::make('edit')->table($own))
        ->assertActionHidden(TestAction::make('delete')->table($own))
        ->callAction(TestAction::make('delete')->table($membership));

    expect(ClientMembership::query()->whereKey($membership->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->pluck('action')->all())->toContain('member.role_changed', 'member.removed');
});

it('hides the member list from people who cannot manage the team', function () {
    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(ManageMembers::class)->assertForbidden();
});

it('never lets someone give a role with more permissions than their own', function () {
    $teamManagerRole = Role::factory()->withPermissions([Permission::ManageTeam, Permission::ViewProspects])->create();
    $manager = User::factory()->withoutClient()->create();
    ClientMembership::query()->create(['user_id' => $manager->id, 'role_id' => $teamManagerRole->id]);
    $adminColleague = User::factory()->withClientRole(SystemRole::Admin)->create();
    $this->actingAs($manager->refreshRoles());

    Livewire::test(ManageMembers::class)
        ->callAction('invite', data: ['email' => 'mine@example.com', 'name' => 'Mine', 'role_id' => Role::system(SystemRole::Admin)->id])
        ->assertHasActionErrors(['role_id']);

    Livewire::test(ManageMembers::class)
        ->assertActionHidden(TestAction::make('edit')->table(ClientMembership::query()->where('user_id', $adminColleague->id)->sole()))
        ->assertActionHidden(TestAction::make('delete')->table(ClientMembership::query()->where('user_id', $adminColleague->id)->sole()));

    expect(User::query()->where('email', 'mine@example.com')->exists())->toBeFalse();
});

it('takes a removed member off the client\'s teams', function () {
    $colleague = User::factory()->create();
    $team = Team::factory()->create(['leader_id' => $colleague->id]);
    $team->members()->attach($colleague);

    ClientMembership::query()->where('user_id', $colleague->id)->sole()->delete();

    expect($team->refresh()->leader_id)->toBeNull()
        ->and($team->members()->count())->toBe(0);
});
