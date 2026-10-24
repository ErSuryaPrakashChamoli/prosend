<?php

it('returns 429 with Retry-After once the per-minute limit is used', function () {
    [, $plaintext] = issueApiKey(['rate_limit_per_minute' => 2]);

    $this->withToken($plaintext)->getJson('/api/v1/me')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
    $this->withToken($plaintext)->getJson('/api/v1/me')->assertOk();

    $this->withToken($plaintext)->getJson('/api/v1/me')
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');
});

it('counts limits per key', function () {
    [, $first, $sender] = issueApiKey(['rate_limit_per_minute' => 1]);
    [, $second] = issueApiKey(['rate_limit_per_minute' => 1], $sender);

    $this->withToken($first)->getJson('/api/v1/me')->assertOk();

    $this->withToken($second)->getJson('/api/v1/me')->assertOk();
});
