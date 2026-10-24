<?php

use App\Jobs\SendEmailMessage;
use App\Models\EmailMessage;
use App\Models\IdempotencyKey;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;

function idempotentPayload(array $overrides = []): array
{
    return ['from' => 'billing@acme.test', 'to' => [['email' => 'jane@example.com']], 'purpose' => 'transactional', 'subject' => 'Receipt', 'html' => '<p>Thanks</p>', ...$overrides];
}

it('returns the original response for a retried request and sends once', function () {
    Queue::fake([SendEmailMessage::class]);
    [, $plaintext] = issueApiKey();

    $first = $this->withToken($plaintext)->withHeader('Idempotency-Key', 'order-1042')->postJson('/api/v1/emails', idempotentPayload());
    $second = $this->withToken($plaintext)->withHeader('Idempotency-Key', 'order-1042')->postJson('/api/v1/emails', idempotentPayload());

    $first->assertAccepted();
    $second->assertAccepted()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.0.id', $first->json('data.0.id'))
        ->assertJsonPath('meta.idempotent_replay', true);
    expect(EmailMessage::query()->count())->toBe(1);
    Queue::assertPushed(SendEmailMessage::class, 1);
});

it('treats the same JSON with keys in another order as the same request', function () {
    Queue::fake([SendEmailMessage::class]);
    [, $plaintext] = issueApiKey();

    $this->withToken($plaintext)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/emails', idempotentPayload())->assertAccepted();
    $this->withToken($plaintext)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/emails', array_reverse(idempotentPayload(), true))->assertAccepted()->assertHeader('Idempotent-Replayed', 'true');

    expect(EmailMessage::query()->count())->toBe(1);
});

it('refuses a reused key with a different body', function () {
    Queue::fake([SendEmailMessage::class]);
    [, $plaintext] = issueApiKey();
    $this->withToken($plaintext)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/emails', idempotentPayload())->assertAccepted();

    $this->withToken($plaintext)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/emails', idempotentPayload(['subject' => 'Different']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'idempotency_key_reused');

    expect(EmailMessage::query()->count())->toBe(1);
});

it('returns 409 while the first request with the key is still running', function () {
    [$apiKey, $plaintext] = issueApiKey();
    $payload = idempotentPayload();
    $hash = hash('sha256', 'POST api/v1/emails'."\n".json_encode(Arr::sortRecursive($payload)));
    IdempotencyKey::query()->create(['api_key_id' => $apiKey->id, 'key' => 'k1', 'request_hash' => $hash, 'status' => IdempotencyKey::STATUS_PROCESSING, 'locked_until' => now()->addMinute()]);

    $this->withToken($plaintext)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/emails', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'idempotency_in_progress');

    expect(EmailMessage::query()->count())->toBe(0);
});

it('recognises messages an interrupted first attempt already created', function () {
    Queue::fake([SendEmailMessage::class]);
    [$apiKey, $plaintext] = issueApiKey();
    $existing = EmailMessage::factory()->create(['api_key_id' => $apiKey->id, 'idempotency_key' => 'k1']);

    $this->withToken($plaintext)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/emails', idempotentPayload())
        ->assertAccepted()
        ->assertJsonPath('data.0.id', $existing->uuid)
        ->assertJsonPath('meta.idempotent_replay', true);

    expect(EmailMessage::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('allows the key again after 24 hours', function () {
    Queue::fake([SendEmailMessage::class]);
    [, $plaintext] = issueApiKey();
    $this->withToken($plaintext)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/emails', idempotentPayload())->assertAccepted();

    $this->travel(25)->hours();

    $this->withToken($plaintext)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/emails', idempotentPayload(['subject' => 'Next day']))->assertAccepted();

    expect(EmailMessage::query()->count())->toBe(2);
});

it('keeps idempotency keys separate per API key', function () {
    Queue::fake([SendEmailMessage::class]);
    [, $first, $sender] = issueApiKey();
    [, $second] = issueApiKey(senderIdentity: $sender);

    $this->withToken($first)->withHeader('Idempotency-Key', 'shared')->postJson('/api/v1/emails', idempotentPayload())->assertAccepted();
    $this->withToken($second)->withHeader('Idempotency-Key', 'shared')->postJson('/api/v1/emails', idempotentPayload())->assertAccepted()->assertHeaderMissing('Idempotent-Replayed');

    expect(EmailMessage::query()->count())->toBe(2);
});
