<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Providers\SmtpHostPolicy;
use App\Services\Webhooks\BlockedWebhookAddress;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one webhook delivery. Only a 2xx response counts as delivered. Timeouts, 5xx, 408 and 429 are retried after 1,
 * 5, 30 minutes, 2 and 6 hours; other 4xx answers and refused addresses fail at once. The host is checked and the
 * connection pinned to the checked address, redirects are not followed, and the response body is never stored.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 30;

    /**
     * A deleted endpoint takes its deliveries with it; nothing is left to send.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public WebhookDelivery $delivery)
    {
        $this->onQueue(config('prosend.automation.queue'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 21600];
    }

    public function handle(WebhookSigner $signer, SmtpHostPolicy $hostPolicy): void
    {
        $delivery = $this->delivery->refresh();
        $endpoint = WebhookEndpoint::acrossClients()->find($delivery->webhook_endpoint_id);

        if ($endpoint === null || $delivery->status === WebhookDelivery::SUCCEEDED) {
            return;
        }

        if (! $endpoint->is_enabled || ! $endpoint->client->is_active) {
            $this->finish($delivery, $endpoint, WebhookDelivery::FAILED, null, $endpoint->is_enabled ? 'The client is deactivated.' : 'The endpoint is turned off.');

            return;
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        try {
            $response = $this->send($delivery, $endpoint, $signer, $hostPolicy);
        } catch (Throwable $exception) {
            $this->retryOrFail($delivery, $endpoint, null, Str::limit($exception->getMessage(), 500), retryable: ! $exception instanceof BlockedWebhookAddress);

            return;
        }

        if ($response->successful()) {
            $this->finish($delivery, $endpoint, WebhookDelivery::SUCCEEDED, $response->status(), null);

            return;
        }

        $retryable = $response->serverError() || in_array($response->status(), [408, 429], true);
        $this->retryOrFail($delivery, $endpoint, $response->status(), "The endpoint answered {$response->status()}.", $retryable);
    }

    private function send(WebhookDelivery $delivery, WebhookEndpoint $endpoint, WebhookSigner $signer, SmtpHostPolicy $hostPolicy): Response
    {
        $url = $endpoint->url;
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        try {
            $address = $hostPolicy->addressFor($host, allowPrivate: (bool) config('prosend.webhooks.allow_private_hosts'));
        } catch (Throwable $exception) {
            throw new BlockedWebhookAddress($exception->getMessage());
        }

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);

        return Http::timeout(10)
            ->connectTimeout(5)
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]],
            ])
            ->withHeaders([
                ...$signer->headers((string) $endpoint->secret, 'msg_'.$delivery->uuid, now()->getTimestamp(), $body),
                'User-Agent' => 'ProSend-Webhooks/1.0',
            ])
            ->withBody($body, 'application/json')
            ->post($url);
    }

    private function retryOrFail(WebhookDelivery $delivery, WebhookEndpoint $endpoint, ?int $statusCode, string $error, bool $retryable): void
    {
        $delay = $this->backoff()[$delivery->attempts - 1] ?? null;

        if ($retryable && $delay !== null) {
            $delivery->forceFill(['last_status_code' => $statusCode, 'last_error' => $error, 'next_attempt_at' => now()->addSeconds($delay)])->save();
            $this->release($delay);

            return;
        }

        $this->finish($delivery, $endpoint, WebhookDelivery::FAILED, $statusCode, $error);
    }

    private function finish(WebhookDelivery $delivery, WebhookEndpoint $endpoint, string $status, ?int $statusCode, ?string $error): void
    {
        $succeeded = $status === WebhookDelivery::SUCCEEDED;

        $delivery->forceFill([
            'status' => $status,
            'last_status_code' => $statusCode,
            'last_error' => $error,
            'next_attempt_at' => null,
            'delivered_at' => $succeeded ? now() : null,
        ])->save();

        $failures = $succeeded ? 0 : $endpoint->consecutive_failures + 1;

        $endpoint->forceFill([
            'consecutive_failures' => $failures,
            $succeeded ? 'last_success_at' : 'last_failure_at' => now(),
            'is_enabled' => $endpoint->is_enabled && $failures < WebhookEndpoint::DISABLE_AFTER_FAILURES,
        ]);

        // Counters change on every delivery; only turning the endpoint off needs the cached "has endpoints" answer forgotten.
        $endpoint->isDirty('is_enabled') ? $endpoint->save() : $endpoint->saveQuietly();
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = $this->delivery->fresh();

        if ($delivery !== null && $delivery->status === WebhookDelivery::PENDING) {
            $delivery->forceFill(['status' => WebhookDelivery::FAILED, 'last_error' => 'Delivery stopped unexpectedly.', 'next_attempt_at' => null])->save();
        }
    }
}
