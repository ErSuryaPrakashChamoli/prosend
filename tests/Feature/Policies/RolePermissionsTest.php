<?php

use App\Enums\CampaignStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\Suppression;
use App\Models\User;

dataset('roles', [
    'admin' => [UserRole::Admin],
    'sender' => [UserRole::Sender],
    'viewer' => [UserRole::Viewer],
]);

it('lets only admins and senders change audiences and send email', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $canWrite = $role !== UserRole::Viewer;

    expect($user->can('viewAny', Contact::class))->toBeTrue()
        ->and($user->can('create', Contact::class))->toBe($canWrite)
        ->and($user->can('update', Contact::factory()->create()))->toBe($canWrite)
        ->and($user->can('create', Campaign::class))->toBe($canWrite)
        ->and($user->can('create', EmailMessage::class))->toBe($canWrite)
        ->and($user->can('operate', Campaign::factory()->create()))->toBe($canWrite);
})->with('roles');

it('lets only admins manage providers, senders, users, suppression removal, and audit logs', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $isAdmin = $role === UserRole::Admin;

    expect($user->can('viewAny', SmtpProvider::class))->toBe($isAdmin)
        ->and($user->can('create', SenderIdentity::class))->toBe($isAdmin)
        ->and($user->can('viewAny', User::class))->toBe($isAdmin)
        ->and($user->can('viewAny', AuditLog::class))->toBe($isAdmin)
        ->and($user->can('delete', Suppression::factory()->create()))->toBe($isAdmin);
})->with('roles');

it('only allows editing draft campaigns', function (CampaignStatus $status, bool $editable) {
    $user = User::factory()->sender()->create();
    $campaign = Campaign::factory()->status($status)->create();

    expect($user->can('update', $campaign))->toBe($editable)
        ->and($user->can('delete', $campaign))->toBe($editable);
})->with([
    'draft' => [CampaignStatus::Draft, true],
    'sending' => [CampaignStatus::Sending, false],
    'completed' => [CampaignStatus::Completed, false],
]);

it('prevents admins from deleting their own account', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('delete', $admin))->toBeFalse()
        ->and($admin->can('delete', User::factory()->create()))->toBeTrue();
});

it('prevents deleting a provider or sender that has sending history', function () {
    $admin = User::factory()->admin()->create();
    $message = EmailMessage::factory()->accepted()->create();
    $message->update([]);
    $message->forceFill(['smtp_provider_id' => $message->senderIdentity->smtp_provider_id])->save();

    expect($admin->can('delete', $message->senderIdentity))->toBeFalse()
        ->and($admin->can('delete', $message->senderIdentity->smtpProvider))->toBeFalse()
        ->and($admin->can('delete', SmtpProvider::factory()->create()))->toBeTrue();
});

it('gives new accounts the viewer role', function () {
    $user = User::query()->create(['name' => 'New', 'email' => 'new@example.com', 'password' => 'secret-password']);

    expect($user->role)->toBe(UserRole::Viewer);
});
