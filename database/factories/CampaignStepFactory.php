<?php

namespace Database\Factories;

use App\Enums\StepCondition;
use App\Models\Campaign;
use App\Models\CampaignStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignStep>
 */
class CampaignStepFactory extends Factory
{
    /**
     * A follow-up that replies in the first email's thread two days later.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'position' => 1,
            'wait_days' => 2,
            'condition' => StepCondition::NoReply,
            'subject' => null,
            'body' => '<p>Just following up, {{ first_name }}.</p>',
        ];
    }
}
