<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignVariant>
 */
class CampaignVariantFactory extends Factory
{
    /**
     * Version B of a campaign's first email, with half the traffic.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'campaign_step_id' => null,
            'position' => 1,
            'weight' => 50,
            'subject' => 'A different subject',
            'body' => '<p>Version B, {{ first_name }}.</p>',
        ];
    }
}
