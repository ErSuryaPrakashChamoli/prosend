<?php

use App\Enums\CampaignStatus;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Jobs\PrepareCampaignMessages;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('launches a draft campaign', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    $this->actingAs(User::factory()->sender()->create());
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('launch')
        ->assertNotified('Campaign launched');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Queued);
    Queue::assertPushed(PrepareCampaignMessages::class, 1);
});

it('requires typing the recipient count for a large audience', function () {
    config(['prosend.campaigns.large_audience_threshold' => 2]);
    Queue::fake([PrepareCampaignMessages::class]);
    $this->actingAs(User::factory()->sender()->create());
    Contact::factory()->consented()->count(2)->create();
    $campaign = Campaign::factory()->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('launch', data: ['confirmation' => '1'])
        ->assertHasActionErrors(['confirmation' => 'in']);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('hides the launch action from viewers', function () {
    $this->actingAs(User::factory()->create());
    $campaign = Campaign::factory()->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertActionHidden('launch');
});

it('pauses a sending campaign', function () {
    $this->actingAs(User::factory()->sender()->create());
    $campaign = Campaign::factory()->prepared()->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('pause')
        ->assertNotified('Campaign paused');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Paused);
});
