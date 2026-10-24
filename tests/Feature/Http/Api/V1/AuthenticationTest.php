<?php

use App\Enums\MessageStatus;
use App\Models\ApiRequestLog;
use App\Models\EmailMessage;
use App\Models\User;

it('returns 401 for a request without an API key', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'missing_api_key')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']])
        ->assertHeader('X-Request-Id');
});

it('returns 401 for an unknown or malformed key', function (string $key) {
    $this->withToken($key)->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_api_key');
})->with([
    'malformed' => ['hello'],
    'unknown' => ['psk_live_AAAAAAAAAAAA_'.str_repeat('a', 40)],
]);

it('refuses a key sent in the URL', function () {
    [, $plaintext] = issueApiKey();

    $this->getJson('/api/v1/me?api_key='.$plaintext)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'key_in_url');
});

it('refuses a revoked key on the next request', function () {
    [$apiKey, $plaintext] = issueApiKey();
    $this->withToken($plaintext)->getJson('/api/v1/me')->assertOk();

    $apiKey->revoke(User::factory()->admin()->create());

    $this->withToken($plaintext)->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'revoked_api_key');
});

it('refuses an expired key', function () {
    [, $plaintext] = issueApiKey(['expires_at' => now()->subMinute()]);

    $this->withToken($plaintext)->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'expired_api_key');
});

it('returns 403 from an IP outside the allowlist', function () {
    [, $plaintext] = issueApiKey(['allowed_ips' => ['203.0.113.0/24']]);

    $this->withToken($plaintext)->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson('/api/v1/me')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ip_not_allowed');
});

it('throttles an IP after repeated invalid keys', function () {
    foreach (range(1, 20) as $attempt) {
        $this->withToken('psk_live_AAAAAAAAAAAA_'.str_repeat('b', 40))->getJson('/api/v1/me')->assertUnauthorized();
    }

    $this->withToken('psk_live_AAAAAAAAAAAA_'.str_repeat('b', 40))->getJson('/api/v1/me')
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'too_many_failed_attempts')
        ->assertHeader('Retry-After');
});

it('describes the calling key without revealing it', function () {
    [$apiKey, $plaintext] = issueApiKey(['daily_send_limit' => 100]);

    $response = $this->withToken($plaintext)->getJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.key', $apiKey->maskedKey())
        ->assertJsonPath('data.allowed_senders', ['billing@acme.test'])
        ->assertJsonPath('data.daily_sends_remaining', 100);
    expect($response->getContent())->not->toContain(substr($plaintext, -40));
});

it('logs each request without its headers or body', function () {
    [$apiKey, $plaintext] = issueApiKey();

    $this->withToken($plaintext)->postJson('/api/v1/emails', ['from' => 'billing@acme.test', 'to' => [['email' => 'jane@example.com']], 'purpose' => 'transactional', 'subject' => 'Hi', 'html' => '<p>Secret content</p>']);

    $log = ApiRequestLog::query()->sole();
    expect($log)
        ->api_key_id->toBe($apiKey->id)
        ->method->toBe('POST')
        ->path->toBe('api/v1/emails')
        ->status->toBe(202);
    expect(json_encode($log->toArray()))
        ->not->toContain(substr($plaintext, -40))
        ->not->toContain('Secret content');
    expect($apiKey->dailyUsage()->sole())->requests->toBe(1)->emails_queued->toBe(1);
});

it('returns 403 for an endpoint the key has no scope for', function () {
    [, $plaintext] = issueApiKey(['scopes' => ['emails:read']]);

    $this->withToken($plaintext)->postJson('/api/v1/emails', [])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'missing_scope');

    expect(EmailMessage::query()->count())->toBe(0);
});

it('returns 400 for a malformed JSON body', function () {
    [, $plaintext] = issueApiKey();

    $this->withToken($plaintext)
        ->call('POST', '/api/v1/emails', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$plaintext, 'HTTP_ACCEPT' => 'application/json'], '{"from": ')
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'invalid_json');

    expect(EmailMessage::query()->whereNot('status', MessageStatus::Queued)->count())->toBe(0);
});
