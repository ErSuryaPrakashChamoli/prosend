<?php

use App\Enums\ApiKeyMode;
use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\SenderIdentity;
use App\Models\User;
use Livewire\Livewire;

it('creates a key and shows it once without storing it in plain text', function () {
    $this->actingAs(User::factory()->admin()->create());
    $sender = SenderIdentity::factory()->create();

    $component = Livewire::test(ManageApiKeys::class)
        ->callAction('create', data: [
            'name' => 'Shop',
            'mode' => ApiKeyMode::Live->value,
            'scopes' => ['emails:send', 'emails:read'],
            'allowed_sender_identity_ids' => [$sender->id],
            'rate_limit_per_minute' => 60,
        ])
        ->assertHasNoActionErrors()
        ->assertActionMounted('showNewKey');

    $plaintext = $component->instance()->mountedActions[0]['arguments']['key'];
    $apiKey = ApiKey::query()->sole();
    expect($plaintext)->toMatch(ApiKey::KEY_PATTERN);
    expect(ApiKey::findByPlaintext($plaintext)?->is($apiKey))->toBeTrue();
    expect(json_encode(ApiKey::query()->toBase()->first()))->not->toContain(substr($plaintext, -40));
    expect(AuditLog::query()->where('action', 'api_key.created')->sole()->properties)->toMatchArray(['name' => 'Shop', 'key' => $apiKey->maskedKey()]);
    expect(json_encode(AuditLog::query()->where('action', 'api_key.created')->sole()->properties))->not->toContain(substr($plaintext, -40));
});

it('requires an allowed sender for a key that can send', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageApiKeys::class)
        ->callAction('create', data: ['name' => 'Shop', 'mode' => 'live', 'scopes' => ['emails:send'], 'allowed_sender_identity_ids' => [], 'rate_limit_per_minute' => 60])
        ->assertHasActionErrors(['allowed_sender_identity_ids' => 'required']);

    expect(ApiKey::query()->count())->toBe(0);
});

it('refuses an invalid IP range', function () {
    $this->actingAs(User::factory()->admin()->create());
    $sender = SenderIdentity::factory()->create();

    Livewire::test(ManageApiKeys::class)
        ->callAction('create', data: ['name' => 'Shop', 'mode' => 'live', 'scopes' => ['emails:read'], 'allowed_sender_identity_ids' => [$sender->id], 'rate_limit_per_minute' => 60, 'allowed_ips' => ['10.0.0.0/99']])
        ->assertHasActionErrors(['allowed_ips.0']);
});

it('revokes a key and records who did it', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    [$apiKey] = issueApiKey();

    Livewire::test(ManageApiKeys::class)->callTableAction('revoke', $apiKey);

    expect($apiKey->refresh())
        ->isRevoked()->toBeTrue()
        ->revoked_by->toBe($admin->id);
    expect(AuditLog::query()->where('action', 'api_key.revoked')->count())->toBe(1);
});

it('rotates a key and shows the new one once', function () {
    $this->actingAs(User::factory()->admin()->create());
    [$apiKey] = issueApiKey();

    Livewire::test(ManageApiKeys::class)
        ->callTableAction('rotate', $apiKey, data: ['grace_days' => 1])
        ->assertActionMounted('showNewKey');

    expect(ApiKey::query()->count())->toBe(2)
        ->and($apiKey->refresh()->expires_at)->not->toBeNull();
});

it('returns 403 to a sender opening the API keys page', function () {
    $this->actingAs(User::factory()->sender()->create());

    $this->get(ApiKeyResource::getUrl())->assertForbidden();
});
