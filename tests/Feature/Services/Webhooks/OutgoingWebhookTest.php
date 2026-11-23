<?php

use App\Enums\OutreachEvent;
use App\Enums\SystemRole;
use App\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Jobs\DeliverWebhook;
use App\Models\Contact;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Providers\SmtpHostPolicy;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    // Public answers for the test hosts; the real resolver is never used in tests.
    FakeDnsResolver::install(addresses: ['hooks.example.com' => ['93.184.216.34']]);
});

it('sends subscribed events, signed the Standard Webhooks way', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
    $endpoint = WebhookEndpoint::factory()->create(['events' => [OutreachEvent::ProspectCreated->value]]);

    Contact::factory()->create(['email' => 'jane@example.com']);

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery)->status->toBe(WebhookDelivery::SUCCEEDED)->last_status_code->toBe(204)->event->toBe('prospect.created');
    Http::assertSent(function (Request $request) use ($endpoint, $delivery): bool {
        $expected = app(WebhookSigner::class)->sign($endpoint->secret, $request->header('webhook-id')[0], (int) $request->header('webhook-timestamp')[0], $request->body());

        return $request->header('webhook-id')[0] === "msg_{$delivery->uuid}"
            && $request->header('webhook-signature')[0] === "v1,{$expected}"
            && $request['type'] === 'prospect.created'
            && $request['data']['prospect']['email'] === 'jane@example.com';
    });
});

it('does not send events the endpoint did not subscribe to', function () {
    Http::fake();
    WebhookEndpoint::factory()->create(['events' => [OutreachEvent::EmailReplied->value]]);

    Contact::factory()->create();

    expect(WebhookDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('retries server errors and gives up on other client errors', function () {
    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::query()->create(['client_id' => $endpoint->client_id, 'webhook_endpoint_id' => $endpoint->id, 'event' => 'email.replied', 'payload' => ['type' => 'email.replied'], 'status' => WebhookDelivery::PENDING]);

    Http::fake(['*' => Http::sequence()->push('', 503)->push('', 404)]);
    (new DeliverWebhook($delivery))->withFakeQueueInteractions()->handle(app(WebhookSigner::class), app(SmtpHostPolicy::class));
    expect($delivery->refresh())->status->toBe(WebhookDelivery::PENDING)->attempts->toBe(1)->next_attempt_at->not->toBeNull();

    (new DeliverWebhook($delivery))->withFakeQueueInteractions()->handle(app(WebhookSigner::class), app(SmtpHostPolicy::class));
    expect($delivery->refresh())->status->toBe(WebhookDelivery::FAILED)->last_status_code->toBe(404);
});

it('never connects to a private or metadata address', function () {
    Http::fake();
    FakeDnsResolver::install(addresses: ['hooks.example.com' => ['169.254.169.254']]);
    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::query()->create(['client_id' => $endpoint->client_id, 'webhook_endpoint_id' => $endpoint->id, 'event' => 'email.replied', 'payload' => [], 'status' => WebhookDelivery::PENDING]);

    (new DeliverWebhook($delivery))->withFakeQueueInteractions()->handle(app(WebhookSigner::class), app(SmtpHostPolicy::class));

    expect($delivery->refresh())->status->toBe(WebhookDelivery::FAILED)->last_error->toContain('link-local');
    Http::assertNothingSent();
});

it('turns an endpoint off after too many failures in a row', function () {
    Http::fake(['*' => Http::response('', 410)]);
    $endpoint = WebhookEndpoint::factory()->create(['events' => [OutreachEvent::ProspectCreated->value]]);
    $endpoint->forceFill(['consecutive_failures' => WebhookEndpoint::DISABLE_AFTER_FAILURES - 1])->save();

    Contact::factory()->create();

    expect($endpoint->refresh()->is_enabled)->toBeFalse();
});

it('refuses plain-http and private webhook addresses in the form, and shows the secret once', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());
    FakeDnsResolver::install(addresses: ['hooks.example.com' => ['93.184.216.34'], 'internal.example.com' => ['169.254.1.1']]);

    Livewire::test(ListWebhookEndpoints::class)
        ->callAction('create', data: ['url' => 'http://hooks.example.com/x', 'events' => ['email.replied']])
        ->assertHasActionErrors(['url']);

    Livewire::test(ListWebhookEndpoints::class)
        ->callAction('create', data: ['url' => 'https://internal.example.com/x', 'events' => ['email.replied']])
        ->assertHasActionErrors(['url']);

    Livewire::test(ListWebhookEndpoints::class)
        ->callAction('create', data: ['url' => 'https://hooks.example.com/x', 'events' => ['email.replied']])
        ->assertHasNoActionErrors()
        ->assertActionMounted('showSecret');

    expect(WebhookEndpoint::query()->sole()->secret)->toStartWith('whsec_');
});

it('keeps webhooks off private addresses even when private SMTP hosts are allowed', function () {
    Http::fake();
    config(['prosend.providers.allow_private_hosts' => true]);
    FakeDnsResolver::install(addresses: ['hooks.example.com' => ['10.0.0.5']]);
    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::query()->create(['client_id' => $endpoint->client_id, 'webhook_endpoint_id' => $endpoint->id, 'event' => 'email.replied', 'payload' => [], 'status' => WebhookDelivery::PENDING]);

    (new DeliverWebhook($delivery))->withFakeQueueInteractions()->handle(app(WebhookSigner::class), app(SmtpHostPolicy::class));

    expect($delivery->refresh()->status)->toBe(WebhookDelivery::FAILED);
    Http::assertNothingSent();
});
