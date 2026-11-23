<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Str;

/**
 * Signs webhook bodies the Standard Webhooks way (https://www.standardwebhooks.com): HMAC-SHA256 over
 * "{webhook-id}.{webhook-timestamp}.{body}" with the endpoint's secret, sent as "v1,{base64 signature}".
 */
class WebhookSigner
{
    /**
     * @return array{webhook-id: string, webhook-timestamp: string, webhook-signature: string}
     */
    public function headers(string $secret, string $messageId, int $timestamp, string $body): array
    {
        return [
            'webhook-id' => $messageId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => 'v1,'.$this->sign($secret, $messageId, $timestamp, $body),
        ];
    }

    public function sign(string $secret, string $messageId, int $timestamp, string $body): string
    {
        $key = base64_decode(Str::after($secret, 'whsec_'), strict: true) ?: $secret;

        return base64_encode(hash_hmac('sha256', "{$messageId}.{$timestamp}.{$body}", $key, true));
    }
}
