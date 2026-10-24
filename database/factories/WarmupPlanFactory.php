<?php

namespace Database\Factories;

use App\Enums\WarmupStatus;
use App\Models\SmtpProvider;
use App\Models\WarmupPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarmupPlan>
 */
class WarmupPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Warm-up '.fake()->unique()->word(),
            'smtp_provider_id' => SmtpProvider::factory(),
            'start_date' => now()->toDateString(),
            'initial_daily_volume' => 50,
            'target_daily_volume' => 1000,
            'ramp_interval_days' => 1,
            'growth_percent' => 30,
        ];
    }

    public function active(?int $currentDailyVolume = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WarmupStatus::Active,
            'current_daily_volume' => $currentDailyVolume ?? $attributes['initial_daily_volume'] ?? 50,
            'approved_at' => now(),
            'last_increased_at' => now()->subDay(),
        ]);
    }
}
