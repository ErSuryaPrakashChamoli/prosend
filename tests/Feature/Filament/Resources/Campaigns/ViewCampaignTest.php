<?php

use App\Enums\CampaignStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Jobs\PrepareCampaignMessages;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('submits a draft campaign for approval', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    $sender = User::factory()->sender()->create();
    $this->actingAs($sender);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertActionHidden('launch')
        ->callAction('submit')
        ->assertNotified('Submitted for approval');

    expect($campaign->refresh())
        ->status->toBe(CampaignStatus::PendingApproval)
        ->submitted_by->toBe($sender->id);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('launches a campaign an administrator approves', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->pendingApproval()->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('launch')
        ->assertNotified('Campaign launched');

    expect($campaign->refresh())
        ->status->toBe(CampaignStatus::Queued)
        ->approved_by->toBe($admin->id);
    Queue::assertPushed(PrepareCampaignMessages::class, 1);
});

it('returns a campaign waiting for approval to draft with a reason', function () {
    $this->actingAs(User::factory()->admin()->create());
    $campaign = Campaign::factory()->pendingApproval()->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('returnToDraft', data: ['note' => 'Use the spring list'])
        ->assertNotified('Campaign returned to draft');

    expect($campaign->refresh())
        ->status->toBe(CampaignStatus::Draft)
        ->review_note->toBe('Use the spring list');
});

it('requires typing the recipient count for a large audience', function () {
    config(['prosend.campaigns.large_audience_threshold' => 2]);
    Queue::fake([PrepareCampaignMessages::class]);
    $this->actingAs(User::factory()->admin()->create());
    Contact::factory()->consented()->count(2)->create();
    $campaign = Campaign::factory()->pendingApproval()->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('launch', data: ['confirmation' => '1'])
        ->assertHasActionErrors(['confirmation' => 'in']);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingApproval);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('hides the launch action from viewers and senders', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $campaign = Campaign::factory()->pendingApproval()->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertActionHidden('launch');
})->with([UserRole::Viewer, UserRole::Sender]);

it('pauses a sending campaign', function () {
    $this->actingAs(User::factory()->sender()->create());
    $campaign = Campaign::factory()->prepared()->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('pause')
        ->assertNotified('Campaign paused');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Paused);
});

it('refuses an approval when the campaign was resubmitted with changes while the review was open', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    $this->actingAs(User::factory()->admin()->create());
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->pendingApproval()->create(['subject' => 'Reviewed subject']);
    $page = Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])->mountAction('launch');

    $this->travel(5)->seconds();
    Campaign::query()->whereKey($campaign->id)->update(['subject' => 'Changed subject', 'submitted_at' => now()]);
    $page->callMountedAction()
        ->assertNotified('Campaign not launched');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingApproval);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});
