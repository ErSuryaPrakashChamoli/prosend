<?php

namespace Database\Factories;

use App\Enums\SuppressionReason;
use App\Models\Suppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suppression>
 */
class SuppressionFactory extends Factory
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
            'reason' => SuppressionReason::HardBounce,
            'source' => 'Factory',
        ];
    }
}
