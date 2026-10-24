<?php

namespace Database\Factories;

use App\Enums\ConsentStatus;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company' => fake()->company(),
            'source' => 'Factory',
            'consent_status' => ConsentStatus::Unknown,
        ];
    }

    public function consented(): static
    {
        return $this->state(fn (array $attributes) => [
            'consent_status' => ConsentStatus::Granted,
            'consent_source' => 'Signup form',
            'consented_at' => now(),
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'unsubscribed_at' => now(),
        ]);
    }
}
