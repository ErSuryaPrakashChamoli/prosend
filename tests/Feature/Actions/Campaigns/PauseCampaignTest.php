<?php

use App\Actions\Campaigns\PauseCampaign;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Validation\ValidationException;

it('pauses a sending campaign', function () {
    $campaign = Campaign::factory()->prepared()->create();

    app(PauseCampaign::class)->handle($campaign);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Paused);
});

it('does not overwrite a campaign that finished after the page was loaded', function () {
    $campaign = Campaign::factory()->prepared()->create();
    Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Completed]);

    expect(fn () => app(PauseCampaign::class)->handle($campaign))
        ->toThrow(ValidationException::class, 'Only scheduled, queued, or sending campaigns can be paused.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Completed);
});
