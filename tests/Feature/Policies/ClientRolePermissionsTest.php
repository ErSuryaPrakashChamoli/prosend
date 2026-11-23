<?php

use App\Enums\CampaignStatus;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\Role;
use App\Models\SmtpProvider;
use App\Models\Suppression;
use App\Models\Team;
use App\Models\User;
use App\Models\WarmupPlan;
use Database\Factories\UserFactory;

it('gives each built-in role its abilities in the client', function (SystemRole $role, array $abilities) {
    $user = User::factory()->withClientRole($role)->create();
    $campaign = Campaign::factory()->create();

    expect([
        'view prospects' => $user->can('viewAny', Contact::class),
        'manage prospects' => $user->can('create', Contact::class),
        'view campaigns' => $user->can('view', $campaign),
        'manage campaigns' => $user->can('create', Campaign::class),
        'operate campaigns' => $user->can('operate', $campaign),
        'approve campaigns' => $user->can('approve', Campaign::factory()->pendingApproval()->create()),
        'templates' => $user->can('create', EmailTemplate::class),
        'compose' => $user->can('create', EmailMessage::class),
        'mailboxes' => $user->can('viewAny', SmtpProvider::class),
        'deliverability' => $user->can('viewAny', WarmupPlan::class),
        'remove suppressions' => $user->can('delete', Suppression::factory()->create()),
        'api keys' => $user->can('viewAny', ApiKey::class),
        'members' => $user->can('viewAny', ClientMembership::class),
        'teams' => $user->can('create', Team::class),
        'audit log' => $user->can('viewAny', AuditLog::class),
        'agency' => $user->can('viewAny', Client::class),
    ])->toBe($abilities);
})->with([
    'admin' => [SystemRole::Admin, ['view prospects' => true, 'manage prospects' => true, 'view campaigns' => true, 'manage campaigns' => true, 'operate campaigns' => true, 'approve campaigns' => true, 'templates' => true, 'compose' => true, 'mailboxes' => true, 'deliverability' => true, 'remove suppressions' => true, 'api keys' => true, 'members' => true, 'teams' => true, 'audit log' => true, 'agency' => false]],
    'team leader' => [SystemRole::TeamLeader, ['view prospects' => true, 'manage prospects' => true, 'view campaigns' => true, 'manage campaigns' => true, 'operate campaigns' => true, 'approve campaigns' => true, 'templates' => true, 'compose' => true, 'mailboxes' => false, 'deliverability' => false, 'remove suppressions' => false, 'api keys' => false, 'members' => false, 'teams' => false, 'audit log' => false, 'agency' => false]],
    'campaign manager' => [SystemRole::CampaignManager, ['view prospects' => true, 'manage prospects' => true, 'view campaigns' => true, 'manage campaigns' => true, 'operate campaigns' => true, 'approve campaigns' => false, 'templates' => true, 'compose' => true, 'mailboxes' => false, 'deliverability' => false, 'remove suppressions' => false, 'api keys' => false, 'members' => false, 'teams' => false, 'audit log' => false, 'agency' => false]],
    'sales executive' => [SystemRole::SalesExecutive, ['view prospects' => true, 'manage prospects' => true, 'view campaigns' => true, 'manage campaigns' => false, 'operate campaigns' => false, 'approve campaigns' => false, 'templates' => false, 'compose' => true, 'mailboxes' => false, 'deliverability' => false, 'remove suppressions' => false, 'api keys' => false, 'members' => false, 'teams' => false, 'audit log' => false, 'agency' => false]],
    'analyst' => [SystemRole::Analyst, ['view prospects' => true, 'manage prospects' => false, 'view campaigns' => true, 'manage campaigns' => false, 'operate campaigns' => false, 'approve campaigns' => false, 'templates' => false, 'compose' => false, 'mailboxes' => false, 'deliverability' => false, 'remove suppressions' => false, 'api keys' => false, 'members' => false, 'teams' => false, 'audit log' => false, 'agency' => false]],
    'guest viewer' => [SystemRole::GuestViewer, ['view prospects' => true, 'manage prospects' => false, 'view campaigns' => true, 'manage campaigns' => false, 'operate campaigns' => false, 'approve campaigns' => false, 'templates' => false, 'compose' => false, 'mailboxes' => false, 'deliverability' => false, 'remove suppressions' => false, 'api keys' => false, 'members' => false, 'teams' => false, 'audit log' => false, 'agency' => false]],
]);

it('gives a Super Admin every permission in every client, and the agency pages', function () {
    $superAdmin = User::factory()->admin()->create();
    $other = Client::factory()->create();

    expect($superAdmin->hasPermission(Permission::ManageMailboxes, $other))->toBeTrue()
        ->and($superAdmin->can('viewAny', Client::class))->toBeTrue()
        ->and($superAdmin->can('viewAny', Role::class))->toBeTrue()
        ->and($superAdmin->can('viewAny', User::class))->toBeTrue()
        ->and($superAdmin->memberships()->exists())->toBeFalse();
});

it('grants exactly the permissions of a custom role', function () {
    $role = Role::factory()->withPermissions([Permission::ViewProspects, Permission::ExportProspects])->create();
    $user = User::factory()->withoutClient()->create();
    ClientMembership::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    expect($user->hasPermission(Permission::ExportProspects))->toBeTrue()
        ->and($user->hasPermission(Permission::ManageProspects))->toBeFalse()
        ->and($user->can('create', Contact::class))->toBeFalse();
});

it('decides permissions per client, by the role in the record\'s client', function () {
    $other = Client::factory()->create();
    $user = User::factory()->withClientRole(SystemRole::GuestViewer)->create();
    UserFactory::addToClient($user, $other, SystemRole::Admin);
    $theirCampaign = asClient($other, fn () => Campaign::factory()->create());
    $myCampaign = Campaign::factory()->create();

    expect($user->can('operate', $theirCampaign))->toBeTrue()
        ->and($user->can('operate', $myCampaign))->toBeFalse()
        ->and($user->hasPermission(Permission::ManageMailboxes, $other))->toBeTrue()
        ->and($user->hasPermission(Permission::ManageMailboxes))->toBeFalse();
});

it('gives a person without a membership no permissions in a client', function () {
    $outsider = User::factory()->withoutClient()->create();

    expect($outsider->hasPermission(Permission::ViewProspects))->toBeFalse()
        ->and($outsider->can('viewAny', Contact::class))->toBeFalse()
        ->and($outsider->canAccessTenant(Client::query()->firstOrFail()))->toBeFalse();
});

it('lets a submitter withdraw their own campaign only while they can still manage campaigns', function () {
    $submitter = User::factory()->withClientRole(SystemRole::CampaignManager)->create();
    $campaign = Campaign::factory()->status(CampaignStatus::PendingApproval)->create(['submitted_by' => $submitter->id]);
    $analyst = User::factory()->withClientRole(SystemRole::Analyst)->create();
    $analystsCampaign = Campaign::factory()->status(CampaignStatus::PendingApproval)->create(['submitted_by' => $analyst->id]);

    expect($submitter->can('returnToDraft', $campaign))->toBeTrue()
        ->and($analyst->can('returnToDraft', $analystsCampaign))->toBeFalse();
});

it('always keeps every permission on the Admin role', function () {
    $admin = Role::system(SystemRole::Admin);

    $admin->update(['permissions' => [Permission::ViewProspects->value]]);

    expect($admin->refresh()->permissions)->toBe(Permission::values());
});

it('drops unknown permissions from a role', function () {
    $role = Role::factory()->create(['permissions' => ['prospects.view', 'launch.missiles']]);

    expect($role->refresh()->permissions)->toBe(['prospects.view']);
});

it('refuses to delete a built-in role, or a role people still have', function () {
    $custom = Role::factory()->create();
    $user = User::factory()->withoutClient()->create();
    ClientMembership::query()->create(['user_id' => $user->id, 'role_id' => $custom->id]);

    expect(fn () => Role::system(SystemRole::Analyst)->delete())->toThrow(LogicException::class, 'built in')
        ->and(fn () => $custom->delete())->toThrow(LogicException::class, 'still given to people');
});

it('grants nothing in a deactivated client, even when only its id is known', function () {
    $user = User::factory()->withClientRole(SystemRole::Admin)->create();
    $client = Client::query()->where('slug', 'default')->sole();
    $campaign = Campaign::factory()->create();

    $client->deactivate();
    $user->refreshRoles();

    expect($user->hasPermission(Permission::OperateCampaigns, $client->id))->toBeFalse()
        ->and($user->can('operate', $campaign))->toBeFalse();
});
