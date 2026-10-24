<?php

namespace Database\Factories;

use App\Enums\EmailPurpose;
use App\Enums\MessageStatus;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_identity_id' => SenderIdentity::factory(),
            'purpose' => EmailPurpose::Transactional,
            'status' => MessageStatus::Queued,
            'to_email' => fake()->unique()->safeEmail(),
            'to_name' => fake()->name(),
            'subject' => fake()->sentence(5),
            'html_body' => '<p>'.fake()->paragraph().'</p>',
            'queued_at' => now(),
        ];
    }

    public function status(MessageStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MessageStatus::Accepted,
            'accepted_at' => now(),
            'attempts' => 1,
        ]);
    }
}
