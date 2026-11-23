<?php

use App\Enums\ProviderType;
use App\Enums\SmtpEncryption;
use App\Filament\Resources\SmtpProviders\Pages\EditSmtpProvider;
use App\Models\AuditLog;
use App\Models\SmtpProvider;
use App\Models\User;
use Livewire\Livewire;

it('never sends the saved password to the browser', function () {
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create(['password' => 'super-secret-key']);

    Livewire::test(EditSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->assertSchemaStateSet(['password' => null])
        ->assertDontSee('super-secret-key');
});

it('keeps the saved password when the field is left empty', function () {
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create(['password' => 'super-secret-key', 'daily_limit' => 300]);

    Livewire::test(EditSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->fillForm(['daily_limit' => 500, 'password' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($provider->refresh())
        ->password->toBe('super-secret-key')
        ->daily_limit->toBe(500);
    expect(AuditLog::query()->where('action', 'provider.updated')->sole()->properties)->toBe([
        'changed_fields' => ['daily_limit'],
        'password_changed' => false,
        'api_key_changed' => false,
        'webhook_token_changed' => false,
    ]);
});

it('stores a new password encrypted and audits the change without the value', function () {
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create(['password' => 'old-key']);

    Livewire::test(EditSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->fillForm(['password' => 'new-key'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($provider->refresh()->password)->toBe('new-key');
    expect($provider->getRawOriginal('password'))->not->toContain('new-key');
    $audit = AuditLog::query()->where('action', 'provider.updated')->sole();
    expect($audit->properties['password_changed'])->toBeTrue();
    expect(json_encode($audit->properties))->not->toContain('new-key');
});

it('requires the password again when the host changes, so a saved secret is never sent to a new server', function () {
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create(['type' => ProviderType::Smtp, 'host' => 'smtp.example.net', 'password' => 'saved-secret']);

    Livewire::test(EditSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->fillForm(['host' => 'smtp.elsewhere.test', 'password' => ''])
        ->call('save')
        ->assertHasFormErrors(['password' => 'required']);

    expect($provider->refresh()->host)->toBe('smtp.example.net');

    Livewire::test(EditSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->fillForm(['host' => 'smtp.elsewhere.test', 'password' => 'new-secret'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($provider->refresh())
        ->host->toBe('smtp.elsewhere.test')
        ->password->toBe('new-secret');
});

it('never switches a provider between sandbox and live sending', function () {
    $this->actingAs(User::factory()->admin()->create());
    $sandbox = SmtpProvider::factory()->mailtrapSandbox()->create();

    Livewire::test(EditSmtpProvider::class, ['record' => $sandbox->getRouteKey()])
        ->fillForm(['type' => ProviderType::Mailtrap->value, 'host' => 'live.smtp.mailtrap.io', 'password' => 'live-token'])
        ->call('save')
        ->assertHasFormErrors(['type']);

    expect($sandbox->refresh()->type)->toBe(ProviderType::MailtrapSandbox);
    expect(fn () => $sandbox->update(['type' => ProviderType::Smtp]))->toThrow(LogicException::class);
});

it('requires the password again before sending it over a connection without encryption', function () {
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create(['type' => ProviderType::Smtp, 'host' => 'smtp.example.net', 'encryption' => SmtpEncryption::StartTls]);

    Livewire::test(EditSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->fillForm(['encryption' => SmtpEncryption::None->value, 'password' => ''])
        ->call('save')
        ->assertHasFormErrors(['password' => 'required']);

    expect($provider->refresh()->encryption)->toBe(SmtpEncryption::StartTls);
});

it('signs in to Mailtrap as "api" when an existing provider switches to Mailtrap, and stores the host without a trailing dot', function () {
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->create(['username' => 'login@smtp-brevo.com']);

    Livewire::test(EditSmtpProvider::class, ['record' => $provider->getRouteKey()])
        ->fillForm(['type' => ProviderType::Mailtrap->value])
        ->assertSchemaStateSet(['username' => 'api', 'host' => 'live.smtp.mailtrap.io'])
        ->fillForm(['host' => 'Bulk.SMTP.mailtrap.io.', 'password' => 'mt-token'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($provider->refresh())
        ->type->toBe(ProviderType::Mailtrap)
        ->username->toBe('api')
        ->host->toBe('bulk.smtp.mailtrap.io');
});
