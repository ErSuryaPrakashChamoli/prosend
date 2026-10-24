<?php

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
