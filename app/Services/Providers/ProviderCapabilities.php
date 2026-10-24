<?php

namespace App\Services\Providers;

/**
 * What a provider can report back beyond accepting a message over SMTP.
 */
final readonly class ProviderCapabilities
{
    public function __construct(
        public bool $deliveryWebhooks = false,
        public bool $engagementTracking = false,
    ) {}
}
