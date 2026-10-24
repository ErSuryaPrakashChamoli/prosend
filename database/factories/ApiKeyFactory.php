<?php

namespace Database\Factories;

use App\Enums\ApiKeyMode;
use App\Enums\ApiScope;
use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * Define the model's default state. Tests that need the plaintext use ApiKey::issue() instead.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Integration '.fake()->unique()->word(),
            'mode' => ApiKeyMode::Live,
            'key_id' => Str::random(12),
            'secret_hash' => hash('sha256', Str::random(40)),
            'last_four' => 'abcd',
            'scopes' => [ApiScope::EmailsSend->value, ApiScope::EmailsRead->value],
            'rate_limit_per_minute' => 60,
        ];
    }
}
