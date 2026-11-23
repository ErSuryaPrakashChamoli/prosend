<?php

use App\Enums\ProviderType;
use App\Enums\SmtpEncryption;
use App\Enums\UserRole;
use App\Filament\Resources\SmtpProviders\Pages\CreateSmtpProvider;
use App\Filament\Resources\SmtpProviders\SmtpProviderResource;
use App\Models\AuditLog;
use App\Models\SmtpProvider;
use App\Models\User;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('fills in Mailtrap\'s documented settings when a Mailtrap type is chosen', function () {
    Livewire::test(CreateSmtpProvider::class)
        ->fillForm(['type' => ProviderType::Mailtrap->value])
        ->assertSchemaStateSet(['host' => 'live.smtp.mailtrap.io', 'port' => 587, 'encryption' => SmtpEncryption::StartTls, 'username' => 'api'])
        ->fillForm(['type' => ProviderType::MailtrapSandbox->value])
        ->assertSchemaStateSet(['host' => 'sandbox.smtp.mailtrap.io', 'port' => 2525]);
});

it('creates a Mailtrap sandbox and a live Mailtrap provider side by side, with their secrets encrypted', function () {
    Livewire::test(CreateSmtpProvider::class)
        ->fillForm([
            'name' => 'Mailtrap sandbox',
            'type' => ProviderType::MailtrapSandbox->value,
            'host' => 'sandbox.smtp.mailtrap.io',
            'port' => 2525,
            'encryption' => SmtpEncryption::StartTls->value,
            'username' => 'a1b2c3d4e5f6a7',
            'password' => 'sandbox-pass-123',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateSmtpProvider::class)
        ->fillForm([
            'name' => 'Mailtrap bulk',
            'type' => ProviderType::Mailtrap->value,
            'host' => 'bulk.smtp.mailtrap.io',
            'port' => 587,
            'encryption' => SmtpEncryption::StartTls->value,
            'username' => 'api',
            'password' => 'live-token-456',
            'daily_limit' => 150,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $sandbox = SmtpProvider::query()->where('name', 'Mailtrap sandbox')->sole();
    $live = SmtpProvider::query()->where('name', 'Mailtrap bulk')->sole();

    expect($sandbox->isSandbox())->toBeTrue()
        ->and($sandbox->password)->toBe('sandbox-pass-123')
        ->and($sandbox->getRawOriginal('password'))->not->toContain('sandbox-pass-123');
    expect($live->isSandbox())->toBeFalse()
        ->and($live->daily_limit)->toBe(150)
        ->and($live->getRawOriginal('password'))->not->toContain('live-token-456');
    expect(AuditLog::query()->where('action', 'provider.created')->count())->toBe(2);
});

it('refuses a host that does not match the provider type, so a sandbox can never pass for live sending', function (string $type, string $host) {
    Livewire::test(CreateSmtpProvider::class)
        ->fillForm(['name' => 'Mismatch', 'type' => $type, 'host' => $host, 'port' => 587, 'encryption' => SmtpEncryption::StartTls->value, 'password' => 'secret'])
        ->call('create')
        ->assertHasFormErrors(['host']);

    expect(SmtpProvider::query()->count())->toBe(0);
})->with([
    'sandbox host on live Mailtrap' => [ProviderType::Mailtrap->value, 'sandbox.smtp.mailtrap.io'],
    'live host on the sandbox' => [ProviderType::MailtrapSandbox->value, 'live.smtp.mailtrap.io'],
    'Mailtrap host as generic SMTP' => [ProviderType::Smtp->value, 'sandbox.smtp.mailtrap.io'],
]);

it('refuses a host that resolves to a private or metadata address', function () {
    config(['prosend.providers.allow_private_hosts' => false]);
    FakeDnsResolver::install(addresses: ['smtp.internal.test' => ['10.1.2.3']]);

    Livewire::test(CreateSmtpProvider::class)
        ->fillForm(['name' => 'Internal', 'type' => ProviderType::Smtp->value, 'host' => 'smtp.internal.test', 'port' => 587, 'encryption' => SmtpEncryption::StartTls->value, 'password' => 'secret'])
        ->call('create')
        ->assertHasFormErrors(['host']);

    Livewire::test(CreateSmtpProvider::class)
        ->fillForm(['name' => 'Metadata', 'type' => ProviderType::Smtp->value, 'host' => '169.254.169.254', 'port' => 80, 'encryption' => SmtpEncryption::None->value, 'password' => 'secret'])
        ->call('create')
        ->assertHasFormErrors(['host']);

    expect(SmtpProvider::query()->count())->toBe(0);
});

it('does not offer the sandbox where sandbox providers are turned off', function () {
    config(['prosend.providers.allow_sandbox' => false]);

    Livewire::test(CreateSmtpProvider::class)
        ->fillForm(['name' => 'Sandbox', 'type' => ProviderType::MailtrapSandbox->value, 'host' => 'sandbox.smtp.mailtrap.io', 'port' => 2525, 'encryption' => SmtpEncryption::StartTls->value, 'password' => 'secret'])
        ->call('create')
        ->assertHasFormErrors(['type']);

    expect(SmtpProvider::query()->count())->toBe(0);
});

it('never serializes provider secrets', function () {
    $provider = SmtpProvider::factory()->mailtrap()->create(['password' => 'live-token-456', 'api_key' => 'api-key-789']);
    $provider->regenerateWebhookToken();

    $serialized = json_encode($provider->refresh());

    expect($serialized)->not->toContain('live-token-456')
        ->and($serialized)->not->toContain('api-key-789')
        ->and(array_keys($provider->toArray()))->not->toContain('password', 'api_key', 'webhook_token_hash');
});

it('keeps senders and viewers out of provider settings', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $provider = SmtpProvider::factory()->mailtrap()->create();

    $this->get(SmtpProviderResource::getUrl('index'))->assertForbidden();
    $this->get(SmtpProviderResource::getUrl('create'))->assertForbidden();
    $this->get(SmtpProviderResource::getUrl('view', ['record' => $provider]))->assertForbidden();
    $this->get(SmtpProviderResource::getUrl('edit', ['record' => $provider]))->assertForbidden();
})->with([UserRole::Sender, UserRole::Viewer]);
