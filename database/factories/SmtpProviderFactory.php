<?php

namespace Database\Factories;

use App\Enums\ProviderHealth;
use App\Enums\ProviderType;
use App\Enums\SmtpEncryption;
use App\Models\SmtpProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmtpProvider>
 */
class SmtpProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Brevo '.fake()->unique()->word(),
            'type' => ProviderType::Brevo,
            'host' => 'smtp-relay.brevo.com',
            'port' => 587,
            'encryption' => SmtpEncryption::StartTls,
            'username' => fake()->userName().'@smtp-brevo.com',
            'password' => fake()->password(16),
            'priority' => 100,
            'is_enabled' => true,
            'health_status' => ProviderHealth::Unknown,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => false,
        ]);
    }

    public function dailyLimit(int $limit): static
    {
        return $this->state(fn (array $attributes) => [
            'daily_limit' => $limit,
        ]);
    }
}
