<?php

namespace Database\Factories;

use App\Enums\OutreachEvent;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'url' => 'https://hooks.example.com/prosend',
            'events' => [OutreachEvent::EmailReplied->value, OutreachEvent::ProspectCreated->value],
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(fn (WebhookEndpoint $endpoint) => $endpoint->secret ??= 'whsec_'.base64_encode(str_repeat('k', 32)));
    }
}
