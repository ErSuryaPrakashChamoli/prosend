<?php

use App\Enums\ProviderHealth;
use App\Filament\Resources\SmtpProviders\Pages\ViewSmtpProvider;
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
