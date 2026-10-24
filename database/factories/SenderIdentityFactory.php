<?php

namespace Database\Factories;

use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SenderIdentity>
 */
class SenderIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'smtp_provider_id' => SmtpProvider::factory(),
            'name' => fake()->company(),
            'email' => fake()->unique()->userName().'@example.com',
            'verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => null,
        ]);
    }
}
