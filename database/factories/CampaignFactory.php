<?php

namespace Database\Factories;

use App\Enums\AudienceType;
use App\Enums\CampaignStatus;
use App\Enums\EmailPurpose;
use App\Models\Campaign;
use App\Models\SenderIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->words(3, true)),
            'purpose' => EmailPurpose::Marketing,
            'status' => CampaignStatus::Draft,
            'sender_identity_id' => SenderIdentity::factory(),
            'subject' => fake()->sentence(5),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'audience_type' => AudienceType::AllContacts,
            'send_rate_per_minute' => 60,
        ];
    }

    public function transactional(): static
    {
        return $this->state(fn (array $attributes) => [
            'purpose' => EmailPurpose::Transactional,
        ]);
    }

    /**
     * A campaign whose messages have all been created, as after preparation finished.
     */
    public function prepared(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::Sending,
            'started_at' => now(),
            'prepared_at' => now(),
        ]);
    }

    public function status(CampaignStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
