<?php

namespace App\Services\Providers;

use App\Enums\ProviderType;
use App\Models\SmtpProvider;
use Illuminate\Contracts\Container\Container;

class MailProviderManager
{
    /**
     * @var array<string, MailProviderDriver>
     */
    private array $drivers = [];

    public function __construct(private Container $container) {}

    public function driver(SmtpProvider $provider): MailProviderDriver
    {
        return $this->drivers[$provider->type->value] ??= $this->container->make(match ($provider->type) {
            ProviderType::Brevo => BrevoDriver::class,
            ProviderType::Smtp => SmtpDriver::class,
        });
    }

    /**
     * Replace the driver used for a provider type.
     */
    public function extend(ProviderType $type, MailProviderDriver $driver): void
    {
        $this->drivers[$type->value] = $driver;
    }
}
