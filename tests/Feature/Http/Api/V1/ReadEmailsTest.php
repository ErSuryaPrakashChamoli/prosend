<?php

use App\Enums\EmailEventType;
use App\Enums\MessageStatus;
use App\Models\EmailMessage;

it('lists only the emails the key sent, newest first', function () {
    [$apiKey, $plaintext] = issueApiKey();
    $older = EmailMessage::factory()->create(['api_key_id' => $apiKey->id]);
    $newer = EmailMessage::factory()->create(['api_key_id' => $apiKey->id]);
    EmailMessage::factory()->create();

    $this->withToken($plaintext)->getJson('/api/v1/emails')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->uuid)
        ->assertJsonPath('data.1.id', $older->uuid);
});

it('lists every email for a key with the read_all scope', function () {
    [, $plaintext] = issueApiKey(['scopes' => ['emails:read', 'emails:read_all']]);
    EmailMessage::factory()->count(3)->create();

    $this->withToken($plaintext)->getJson('/api/v1/emails')->assertOk()->assertJsonCount(3, 'data');
});

it('filters by status and pages with a cursor', function () {
    [$apiKey, $plaintext] = issueApiKey();
    EmailMessage::factory()->count(3)->accepted()->create(['api_key_id' => $apiKey->id]);
    EmailMessage::factory()->status(MessageStatus::Failed)->create(['api_key_id' => $apiKey->id]);

    $page = $this->withToken($plaintext)->getJson('/api/v1/emails?status=accepted&limit=2')->assertOk()->assertJsonCount(2, 'data');
    $next = $this->withToken($plaintext)->getJson('/api/v1/emails?status=accepted&limit=2&cursor='.$page->json('meta.next_cursor'));

    $next->assertOk()->assertJsonCount(1, 'data');
});

it('returns 404 for another key\'s email', function () {
    [, $plaintext] = issueApiKey();
    $other = EmailMessage::factory()->create();

    $this->withToken($plaintext)->getJson("/api/v1/emails/{$other->uuid}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
    $this->withToken($plaintext)->getJson("/api/v1/emails/{$other->uuid}/events")->assertNotFound();
});

it('returns 401, not 404, for an unknown email without a key', function () {
    $this->getJson('/api/v1/emails/0198f6b2-0000-7000-8000-000000000000')->assertUnauthorized();
});

it('returns the event history in order', function () {
    [$apiKey, $plaintext] = issueApiKey();
    $email = EmailMessage::factory()->create(['api_key_id' => $apiKey->id]);
    $email->recordEvent(EmailEventType::Queued, occurredAt: now()->subMinutes(2));
    $email->recordEvent(EmailEventType::Accepted, occurredAt: now()->subMinute());

    $this->withToken($plaintext)->getJson("/api/v1/emails/{$email->uuid}/events")
        ->assertOk()
        ->assertJsonPath('data.0.type', 'queued')
        ->assertJsonPath('data.1.type', 'accepted');
});
