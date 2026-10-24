<?php

namespace Database\Factories;

use App\Models\SendingDomain;
use App\Models\SmtpProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SendingDomain>
 */
class SendingDomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain' => fake()->unique()->domainName(),
            'smtp_provider_id' => SmtpProvider::factory(),
        ];
    }
}
