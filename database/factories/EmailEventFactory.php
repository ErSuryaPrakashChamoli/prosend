<?php

namespace Database\Factories;

use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailEvent>
 */
class EmailEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_message_id' => EmailMessage::factory(),
            'type' => EmailEventType::Accepted,
            'source' => EventSource::Application,
            'occurred_at' => now(),
        ];
    }
}
