<?php

use App\Enums\BounceType;
use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Enums\MessageStatus;
use App\Enums\ProviderType;
use App\Enums\SuppressionReason;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\Suppression;

function acceptedMessage(SmtpProvider $provider, array $attributes = []): EmailMessage
{
    return EmailMessage::factory()
        ->for(SenderIdentity::factory()->for($provider))
        ->accepted()
        ->create(['smtp_provider_id' => $provider->id, 'to_email' => 'jane@example.com', ...$attributes]);
}

function brevoEvent(EmailMessage $message, string $event, array $extra = []): array
{
    return ['event' => $event, 'email' => $message->to_email, 'message-id' => "<{$message->provider_message_id}>", 'ts_event' => 1793000000, ...$extra];
}

it('returns 404 when the token does not match', function () {
    $provider = SmtpProvider::factory()->create();
    $provider->regenerateWebhookToken();
    $message = acceptedMessage($provider, ['provider_message_id' => 'abc@acme.test']);

    $this->postJson(route('webhooks.brevo', ['provider' => $provider, 'token' => 'wrong-token']), brevoEvent($message, 'delivered'))
        ->assertNotFound();

    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

it('returns 404 for a provider that is not a Brevo provider', function () {
    $provider = SmtpProvider::factory()->create(['type' => ProviderType::Smtp]);
    $token = $provider->regenerateWebhookToken();

    $this->postJson(route('webhooks.brevo', ['provider' => $provider, 'token' => $token]), [])
        ->assertNotFound();
});

it('marks the message delivered and records the provider event', function () {
    $provider = SmtpProvider::factory()->create();
    $token = $provider->regenerateWebhookToken();
    $message = acceptedMessage($provider, ['provider_message_id' => 'abc@acme.test']);

    $this->postJson(route('webhooks.brevo', ['provider' => $provider, 'token' => $token]), brevoEvent($message, 'delivered'))
        ->assertNoContent();

    expect($message->refresh())
        ->status->toBe(MessageStatus::Delivered)
        ->delivered_at->getTimestamp()->toBe(1793000000);
    expect($message->events()->where('source', EventSource::BrevoWebhook)->sole())
        ->type->toBe(EmailEventType::Delivered)
        ->recipient_email->toBe('jane@example.com');
});

it('suppresses the address after a hard bounce', function () {
    $provider = SmtpProvider::factory()->create();
    $token = $provider->regenerateWebhookToken();
    $message = acceptedMessage($provider, ['provider_message_id' => 'abc@acme.test']);

    $this->postJson(route('webhooks.brevo', ['provider' => $provider, 'token' => $token]), [
        brevoEvent($message, 'hard_bounce', ['reason' => '550 5.1.1 User unknown']),
    ])->assertNoContent();

    expect($message->refresh())
        ->status->toBe(MessageStatus::Bounced)
        ->bounce_type->toBe(BounceType::Hard)
        ->error_message->toBe('550 5.1.1 User unknown');
    expect(Suppression::query()->sole())
        ->email->toBe('jane@example.com')
        ->reason->toBe(SuppressionReason::HardBounce);
});

it('suppresses the address and keeps the stronger status after a spam complaint', function () {
    $provider = SmtpProvider::factory()->create();
    $token = $provider->regenerateWebhookToken();
    $message = acceptedMessage($provider, ['provider_message_id' => 'abc@acme.test']);
    Suppression::factory()->create(['email' => 'jane@example.com', 'reason' => SuppressionReason::Unsubscribed]);

    $this->postJson(route('webhooks.brevo', ['provider' => $provider, 'token' => $token]), [
        brevoEvent($message, 'spam'),
        brevoEvent($message, 'delivered', ['ts_event' => 1792999990]),
    ])->assertNoContent();

    expect($message->refresh()->status)->toBe(MessageStatus::Complained);
    expect(Suppression::query()->sole()->reason)->toBe(SuppressionReason::Complaint);
});

it('records an unsubscribe reported by Brevo', function () {
    $provider = SmtpProvider::factory()->create();
    $token = $provider->regenerateWebhookToken();
    $contact = Contact::factory()->consented()->create(['email' => 'jane@example.com']);
    $message = acceptedMessage($provider, ['provider_message_id' => 'abc@acme.test', 'contact_id' => $contact->id]);

    $this->postJson(route('webhooks.brevo', ['provider' => $provider, 'token' => $token]), brevoEvent($message, 'unsubscribed'))
        ->assertNoContent();

    expect($contact->refresh()->unsubscribed_at)->not->toBeNull();
    expect(Suppression::query()->sole()->reason)->toBe(SuppressionReason::Unsubscribed);
});

it('stores a redelivered event only once', function () {
    $provider = SmtpProvider::factory()->create();
    $token = $provider->regenerateWebhookToken();
    $message = acceptedMessage($provider, ['provider_message_id' => 'abc@acme.test']);
    $url = route('webhooks.brevo', ['provider' => $provider, 'token' => $token]);

    $this->postJson($url, brevoEvent($message, 'opened'))->assertNoContent();
    $this->postJson($url, brevoEvent($message, 'opened'))->assertNoContent();

    expect($message->events()->where('type', EmailEventType::Opened)->count())->toBe(1);
});

it('matches the message by the X-Mailin-custom header when the Message-ID differs', function () {
    $provider = SmtpProvider::factory()->create();
    $token = $provider->regenerateWebhookToken();
    $message = acceptedMessage($provider, ['provider_message_id' => 'abc@acme.test']);

    $this->postJson(route('webhooks.brevo', ['provider' => $provider, 'token' => $token]), [
        'event' => 'delivered',
        'email' => 'jane@example.com',
        'message-id' => '<rewritten@brevo>',
        'X-Mailin-custom' => $message->uuid,
    ])->assertNoContent();

    expect($message->refresh()->status)->toBe(MessageStatus::Delivered);
});

it('accepts events for messages ProSend did not send', function () {
    $provider = SmtpProvider::factory()->create();
    $token = $provider->regenerateWebhookToken();

    $this->postJson(route('webhooks.brevo', ['provider' => $provider, 'token' => $token]), [
        'event' => 'delivered',
        'email' => 'someone@example.com',
        'message-id' => '<unknown@example.com>',
    ])->assertNoContent();

    expect(Suppression::query()->count())->toBe(0);
});

it('ignores events for messages sent through a different provider', function () {
    $provider = SmtpProvider::factory()->create();
    $token = $provider->regenerateWebhookToken();
    $otherMessage = acceptedMessage(SmtpProvider::factory()->create(), ['provider_message_id' => 'other@acme.test']);

    $this->postJson(route('webhooks.brevo', ['provider' => $provider, 'token' => $token]), brevoEvent($otherMessage, 'hard_bounce'))
        ->assertNoContent();

    expect($otherMessage->refresh()->status)->toBe(MessageStatus::Accepted);
    expect(Suppression::query()->count())->toBe(0);
});
