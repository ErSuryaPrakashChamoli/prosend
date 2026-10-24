<?php

use App\Models\ApiKey;
use App\Models\User;

it('stores only a hash of the secret and finds the key from its plaintext', function () {
    [$apiKey, $plaintext] = issueApiKey();

    expect($plaintext)->toMatch(ApiKey::KEY_PATTERN);
    expect($apiKey->getRawOriginal('secret_hash'))
        ->toHaveLength(64)
        ->not->toContain(substr($plaintext, -40));
    expect(ApiKey::findByPlaintext($plaintext)?->is($apiKey))->toBeTrue();
    expect($apiKey->toArray())->not->toHaveKey('secret_hash');
});

it('rejects a plaintext with the right key id but the wrong secret or mode', function () {
    [$apiKey, $plaintext] = issueApiKey();

    expect(ApiKey::findByPlaintext(substr($plaintext, 0, -1).'X'))->toBeNull()
        ->and(ApiKey::findByPlaintext(str_replace('psk_live_', 'psk_test_', $plaintext)))->toBeNull()
        ->and(ApiKey::findByPlaintext('not-a-key'))->toBeNull();
});

it('keeps the old key working until the rotation grace period ends', function () {
    $this->freezeSecond();
    [$apiKey] = issueApiKey(['daily_send_limit' => 500]);

    [$successor, $plaintext] = $apiKey->rotate(3, User::factory()->admin()->create());

    expect($successor)
        ->rotated_from_id->toBe($apiKey->id)
        ->daily_send_limit->toBe(500)
        ->scopes->toBe($apiKey->scopes);
    expect(ApiKey::findByPlaintext($plaintext)?->is($successor))->toBeTrue();
    expect($apiKey->refresh())
        ->isActive()->toBeTrue()
        ->expires_at->toDateTimeString()->toBe(now()->addDays(3)->toDateTimeString());

    $this->travel(3)->days();
    $this->travel(1)->seconds();

    expect($apiKey->refresh()->isActive())->toBeFalse();
});

it('revokes the old key at once when rotated without a grace period', function () {
    [$apiKey] = issueApiKey();

    $apiKey->rotate(0, User::factory()->admin()->create());

    expect($apiKey->refresh()->isRevoked())->toBeTrue();
});

it('only allows listed IP addresses and ranges when an allowlist is set', function () {
    [$apiKey] = issueApiKey(['allowed_ips' => ['203.0.113.0/24', '2001:db8::1']]);

    expect($apiKey->allowsIp('203.0.113.9'))->toBeTrue()
        ->and($apiKey->allowsIp('2001:db8::1'))->toBeTrue()
        ->and($apiKey->allowsIp('198.51.100.1'))->toBeFalse();
});
