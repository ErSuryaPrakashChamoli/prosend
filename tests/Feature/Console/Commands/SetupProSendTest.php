<?php

use App\Enums\ProviderType;
use App\Enums\SmtpEncryption;
use App\Enums\UserRole;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\User;

beforeEach(function () {
    config([
        'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.scheme' => 'smtp',
        'mail.mailers.smtp.username' => 'login@smtp-brevo.com',
        'mail.mailers.smtp.password' => 'xsmtpsib-secret',
        'mail.from.address' => 'news@acme.test',
        'mail.from.name' => 'Acme',
    ]);
});

it('imports the SMTP settings as an encrypted Brevo provider with an unverified sender', function () {
    $this->artisan('prosend:setup', ['--skip-admin' => true])->assertSuccessful();

    $provider = SmtpProvider::query()->sole();
    expect($provider)
        ->type->toBe(ProviderType::Brevo)
        ->host->toBe('smtp-relay.brevo.com')
        ->encryption->toBe(SmtpEncryption::StartTls)
        ->password->toBe('xsmtpsib-secret')
        ->daily_limit->toBe(300);
    expect($provider->getRawOriginal('password'))->not->toContain('xsmtpsib-secret');
    expect(SenderIdentity::query()->sole())
        ->email->toBe('news@acme.test')
        ->verified_at->toBeNull();
});

it('does not import the settings again once a provider exists', function () {
    SmtpProvider::factory()->create();

    $this->artisan('prosend:setup', ['--skip-admin' => true])->assertSuccessful();

    expect(SmtpProvider::query()->count())->toBe(1);
});

it('creates the first administrator', function () {
    $this->artisan('prosend:setup')
        ->expectsQuestion('Administrator name', 'Ada Admin')
        ->expectsQuestion('Administrator email', 'ada@acme.test')
        ->expectsQuestion('Password', 'a-Strong-password-123')
        ->assertSuccessful();

    expect(User::query()->sole())
        ->email->toBe('ada@acme.test')
        ->role->toBe(UserRole::Admin);
});
