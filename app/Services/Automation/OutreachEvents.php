<?php

namespace App\Services\Automation;

use App\Enums\OutreachEvent;
use App\Jobs\DeliverWebhook;
use App\Jobs\RunAutomationRules;
use App\Models\AutomationRule;
use App\Models\Client;
use App\Models\Contact;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The one place outreach events are announced. Each event starts the client's matching automation rules and goes to its
 * subscribed webhook endpoints, both on the queue, so whatever caused the event is never slowed down or broken by them.
 *
 * Rules started by an automation's own actions are limited to MAX_DEPTH levels, so two rules can never trigger each
 * other forever. Webhooks are always sent.
 */
class OutreachEvents
{
    public const MAX_DEPTH = 3;

    private int $depth = 0;

    /**
     * @param  array<string, mixed>  $data  Event details for webhooks and conditions, such as campaign_id.
     * @param  string|null  $occurrence  Identifies this occurrence, so a redelivered or repeated cause runs each rule once.
     */
    public function dispatch(OutreachEvent $event, int $clientId, ?Contact $contact = null, array $data = [], ?string $occurrence = null, bool $startsRules = true): void
    {
        rescue(function () use ($event, $clientId, $contact, $data, $occurrence, $startsRules): void {
            $occurrence ??= (string) Str::uuid();

            if (! $this->clientIsActive($clientId)) {
                return;
            }

            if ($startsRules && $this->depth < self::MAX_DEPTH && $this->hasRulesFor($clientId, $event)) {
                RunAutomationRules::dispatch($event, $clientId, $contact?->getKey(), $data, $occurrence, $this->depth + 1);
            }

            if ($this->hasEndpointsFor($clientId)) {
                $this->sendToEndpoints($event, $clientId, $contact, $data);
            }
        });
    }

    /**
     * Run the callback as part of an automation, so events it causes are one level deeper.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function atDepth(int $depth, callable $callback): mixed
    {
        $previous = $this->depth;
        $this->depth = $depth;

        try {
            return $callback();
        } finally {
            $this->depth = $previous;
        }
    }

    /**
     * The webhook body for an event, the same for every endpoint.
     *
     * @param  array<string, mixed>  $data
     * @return array{type: string, timestamp: string, data: array<string, mixed>}
     */
    public static function payload(OutreachEvent $event, ?Contact $contact, array $data): array
    {
        return [
            'type' => $event->value,
            'timestamp' => now()->toIso8601String(),
            'data' => array_filter([
                ...$data,
                'prospect' => $contact === null ? null : [
                    'id' => $contact->getKey(),
                    'email' => $contact->email,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'company' => $contact->company,
                    'stage' => $contact->stage?->value,
                    'tags' => $contact->tags ?? [],
                ],
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendToEndpoints(OutreachEvent $event, int $clientId, ?Contact $contact, array $data): void
    {
        WebhookEndpoint::acrossClients()
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->listensTo($event))
            ->each(fn (WebhookEndpoint $endpoint) => $this->queueDelivery($endpoint, $event, self::payload($event, $contact, $data)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function queueDelivery(WebhookEndpoint $endpoint, OutreachEvent $event, array $payload): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create([
            'client_id' => $endpoint->client_id,
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event' => $event->value,
            'payload' => $payload,
            'status' => WebhookDelivery::PENDING,
        ]);

        DeliverWebhook::dispatch($delivery);

        return $delivery;
    }

    /**
     * Cached briefly: most events, such as every email sent, belong to clients with no rules for them.
     */
    private function hasRulesFor(int $clientId, OutreachEvent $event): bool
    {
        return Cache::remember("automation-rules:{$clientId}:{$event->value}", 30, fn (): bool => AutomationRule::acrossClients()
            ->where('client_id', $clientId)
            ->where('trigger', $event)
            ->where('is_active', true)
            ->exists());
    }

    private function clientIsActive(int $clientId): bool
    {
        return Cache::remember("client-active:{$clientId}", 30, fn (): bool => (bool) Client::query()->whereKey($clientId)->value('is_active'));
    }

    private function hasEndpointsFor(int $clientId): bool
    {
        return Cache::remember("webhook-endpoints:{$clientId}", 30, fn (): bool => WebhookEndpoint::acrossClients()
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->exists());
    }

    /**
     * Forget the cached answers after rules or endpoints change.
     */
    public static function forgetClient(int $clientId): void
    {
        Cache::forget("webhook-endpoints:{$clientId}");
        Cache::forget("client-active:{$clientId}");

        foreach (OutreachEvent::cases() as $event) {
            Cache::forget("automation-rules:{$clientId}:{$event->value}");
        }
    }
}
