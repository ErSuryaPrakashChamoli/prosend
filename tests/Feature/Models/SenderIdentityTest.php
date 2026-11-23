<?php

use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Filament\Resources\SenderIdentities\Pages\ManageSenderIdentities;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\User;
use Livewire\Livewire;

it('clears the verification when the address changes', function () {
    $senderIdentity = SenderIdentity::factory()->create(['email' => 'news@acme.test']);

    $senderIdentity->update(['email' => 'hello@acme.test']);

    expect($senderIdentity->refresh()->verified_at)->toBeNull();
});

it('keeps the verification when only the name changes', function () {
    $senderIdentity = SenderIdentity::factory()->create();

    $senderIdentity->update(['name' => 'Acme News']);

    expect($senderIdentity->refresh()->verified_at)->not->toBeNull();
});

it('clears the verification when the sender moves to another provider', function () {
    $senderIdentity = SenderIdentity::factory()->create();

    $senderIdentity->update(['smtp_provider_id' => SmtpProvider::factory()->create()->id]);

    expect($senderIdentity->refresh()->verified_at)->toBeNull();
});

it('refuses to move between a sandbox and a live provider while email is waiting to send', function () {
    $sandbox = SmtpProvider::factory()->mailtrapSandbox()->create();
    $live = SmtpProvider::factory()->create();
    $senderIdentity = SenderIdentity::factory()->for($sandbox)->create(['email' => 'qa@acme.test']);
    EmailMessage::factory()->for($senderIdentity)->create(['status' => MessageStatus::Queued]);

    expect($senderIdentity->providerChangeProblem($live))->toStartWith('qa@acme.test has 1 email(s) waiting to send and 0 active campaign(s).');
    expect(fn () => $senderIdentity->update(['smtp_provider_id' => $live->id]))->toThrow(LogicException::class);
    expect($senderIdentity->refresh()->smtp_provider_id)->toBe($sandbox->id);
});

it('refuses the move while a launched campaign could still create email', function () {
    $live = SmtpProvider::factory()->create();
    $senderIdentity = SenderIdentity::factory()->for($live)->create();
    Campaign::factory()->status(CampaignStatus::Scheduled)->create(['sender_identity_id' => $senderIdentity->id]);

    expect($senderIdentity->providerChangeProblem(SmtpProvider::factory()->mailtrapSandbox()->create()))->toContain('1 active campaign(s)');
});

it('moves between live providers freely, and between sandbox and live once nothing is waiting, auditing each move', function () {
    $this->actingAs(User::factory()->admin()->create());
    $brevo = SmtpProvider::factory()->create(['name' => 'Brevo']);
    $mailtrap = SmtpProvider::factory()->mailtrap()->create(['name' => 'Mailtrap']);
    $sandbox = SmtpProvider::factory()->mailtrapSandbox()->create(['name' => 'Sandbox']);
    $senderIdentity = SenderIdentity::factory()->for($brevo)->create(['email' => 'news@acme.test']);
    EmailMessage::factory()->for($senderIdentity)->create(['status' => MessageStatus::Accepted]);
    EmailMessage::factory()->for($senderIdentity)->create(['status' => MessageStatus::Queued]);

    $senderIdentity->update(['smtp_provider_id' => $mailtrap->id]);

    expect(AuditLog::query()->where('action', 'sender.provider_changed')->sole()->properties)->toBe([
        'email' => 'news@acme.test',
        'from' => 'Brevo',
        'to' => 'Mailtrap',
        'from_sandbox' => false,
        'to_sandbox' => false,
    ]);

    EmailMessage::query()->where('status', MessageStatus::Queued)->update(['status' => MessageStatus::Cancelled]);
    $senderIdentity->refresh()->update(['smtp_provider_id' => $sandbox->id]);

    expect($senderIdentity->refresh()->smtp_provider_id)->toBe($sandbox->id)
        ->and($senderIdentity->verified_at)->toBeNull();
});

it('shows the move refusal in the sender identity form', function () {
    $this->actingAs(User::factory()->admin()->create());
    $senderIdentity = SenderIdentity::factory()->for(SmtpProvider::factory()->mailtrapSandbox())->create();
    EmailMessage::factory()->for($senderIdentity)->create(['status' => MessageStatus::Deferred]);
    $live = SmtpProvider::factory()->create();

    Livewire::test(ManageSenderIdentities::class)
        ->callTableAction('edit', $senderIdentity, data: ['smtp_provider_id' => $live->id])
        ->assertHasTableActionErrors(['smtp_provider_id']);
});

it('leaves sandbox identities out of the usable senders where sandbox providers are turned off', function () {
    $live = SenderIdentity::factory()->create();
    $sandbox = SenderIdentity::factory()->for(SmtpProvider::factory()->mailtrapSandbox())->create();

    expect(SenderIdentity::query()->usable()->pluck('id')->all())->toEqualCanonicalizing([$live->id, $sandbox->id]);

    config(['prosend.providers.allow_sandbox' => false]);

    expect(SenderIdentity::query()->usable()->pluck('id')->all())->toBe([$live->id]);
});
