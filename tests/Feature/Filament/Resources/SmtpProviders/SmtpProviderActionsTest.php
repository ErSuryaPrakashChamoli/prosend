<?php

use App\Enums\MessageStatus;
use App\Enums\ProviderHealth;
use App\Filament\Resources\EmailMessages\EmailMessageResource;
use App\Filament\Resources\SmtpProviders\Pages\ViewSmtpProvider;
use App\Filament\Resources\SmtpProviders\RelationManagers\SendAttemptsRelationManager;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\EmailMessageAttempt;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Fakes\FakeMailProviderDriver;

it('marks the provider healthy after a successful connection test', function () {
    $driver = FakeMailProviderDriver::install();
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create();

    Livewire::test(ViewSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->callAction('testConnection');

    expect($driver->connectionTests)->toBe(1);
    expect($provider->refresh())
        ->health_status->toBe(ProviderHealth::Healthy)
        ->last_connected_at->not->toBeNull();
});

it('records the failure without revealing the SMTP login', function () {
    FakeMailProviderDriver::install()->failWith(new TransportException('Failed to authenticate on SMTP server with username "abc123@smtp-brevo.com".', 535));
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create(['username' => 'abc123@smtp-brevo.com']);

    Livewire::test(ViewSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->callAction('testConnection')
        ->assertNotified('Connection failed');

    expect($provider->refresh())
        ->health_status->toBe(ProviderHealth::Failing)
        ->last_error->toContain('[redacted]')
        ->last_error->not->toContain('abc123@smtp-brevo.com');
});

it('shows a new webhook URL that accepts events', function () {
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create();

    Livewire::test(ViewSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->callAction('generateWebhookUrl');

    expect($provider->refresh()->webhook_token_hash)->toHaveLength(64);
});

it('audits each connection test without the credentials', function () {
    FakeMailProviderDriver::install()->failWith(new TransportException('Failed to authenticate on SMTP server with username "abc123@smtp-brevo.com".', 535));
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create(['username' => 'abc123@smtp-brevo.com']);

    Livewire::test(ViewSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->callAction('testConnection');

    $audit = AuditLog::query()->where('action', 'provider.connection_tested')->sole();
    expect($audit->properties['result'])->toBe('failed')
        ->and(json_encode($audit->properties))->not->toContain('abc123@smtp-brevo.com');
});

it('reports a sandbox test email as captured, not delivered', function () {
    $driver = FakeMailProviderDriver::install();
    $admin = User::factory()->admin()->create(['email' => 'qa@acme.test']);
    $this->actingAs($admin);
    $provider = SmtpProvider::factory()->mailtrapSandbox()->create();
    $sender = SenderIdentity::factory()->for($provider)->create();

    Livewire::test(ViewSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->callAction('sendTestEmail', ['sender_identity_id' => $sender->getKey()])
        ->assertNotified('Test email: Captured in sandbox');

    expect($driver->sent)->toHaveCount(1);
    expect(EmailMessage::query()->sole())
        ->status->toBe(MessageStatus::Captured)
        ->smtp_provider_id->toBe($provider->getKey())
        ->delivered_at->toBeNull();
});

it('links to the provider\'s sending history and lists its send attempts, including failures', function () {
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create();
    $message = EmailMessage::factory()->create(['attempts' => 1]);
    $failed = $message->startAttempt($provider);
    $failed->finish(EmailMessageAttempt::OUTCOME_FAILED, '550', '550 5.1.1 Mailbox does not exist');

    Livewire::test(ViewSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->assertActionHasUrl('viewSendingHistory', EmailMessageResource::getUrl('index', ['filters' => ['smtpProvider' => ['value' => $provider->getKey()]]]));

    Livewire::test(SendAttemptsRelationManager::class, ['ownerRecord' => $provider, 'pageClass' => ViewSmtpProvider::class])
        ->assertCanSeeTableRecords([$failed])
        ->assertSee('550 5.1.1 Mailbox does not exist');
});
