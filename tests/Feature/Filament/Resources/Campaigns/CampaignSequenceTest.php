<?php

use App\Enums\CampaignStatus;
use App\Enums\SequenceStatus;
use App\Enums\StepCondition;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Filament\Resources\Campaigns\RelationManagers\ProspectsRelationManager;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignStep;
use App\Models\Contact;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\Campaigns\CampaignReadiness;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('creates a campaign with follow-ups, a sending schedule and tracking', function () {
    $this->actingAs(User::factory()->sender()->create());
    $sender = SenderIdentity::factory()->create();

    Livewire::test(CreateCampaign::class)
        ->fillForm([
            'name' => 'Q4 outreach',
            'sender_identity_id' => $sender->id,
            'subject' => 'Quick question',
            'body' => '<p>Hi</p>',
            'send_days' => [1, 3, 5],
            'send_window_start' => '10:00',
            'send_window_end' => '16:00',
            'timezone' => 'Asia/Kolkata',
            'track_opens' => true,
            'steps' => [
                ['wait_days' => 3, 'condition' => StepCondition::NoReply->value, 'subject' => null, 'body' => '<p>Following up</p>'],
                ['wait_days' => 4, 'condition' => StepCondition::NotOpened->value, 'subject' => 'Another idea', 'body' => '<p>Last try</p>'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = Campaign::query()->sole();
    expect($campaign)
        ->send_days->toBe([1, 3, 5])
        ->send_window_start->toBe('10:00')
        ->send_window_end->toBe('16:00')
        ->timezone->toBe('Asia/Kolkata')
        ->track_opens->toBeTrue()
        ->track_clicks->toBeFalse();
    expect($campaign->steps->map->only(['position', 'wait_days', 'subject'])->all())->toBe([
        ['position' => 1, 'wait_days' => 3, 'subject' => null],
        ['position' => 2, 'wait_days' => 4, 'subject' => 'Another idea'],
    ]);
});

it('refuses a sending window that ends before it starts', function () {
    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(CreateCampaign::class)
        ->fillForm(['name' => 'Bad window', 'send_window_start' => '17:00', 'send_window_end' => '09:00'])
        ->call('create')
        ->assertHasFormErrors(['send_window_end']);
});

it('blocks submission while a follow-up has no content', function () {
    $campaign = Campaign::factory()->create();
    CampaignStep::factory()->for($campaign)->create(['body' => '<p></p>']);

    expect(app(CampaignReadiness::class)->assess($campaign)->blockingIssues)
        ->toContain('Add content to follow-up step 2.');
});

it('copies follow-ups when a campaign is duplicated', function () {
    $this->actingAs(User::factory()->sender()->create());
    $campaign = Campaign::factory()->status(CampaignStatus::Completed)->create(['archived_at' => now()]);
    CampaignStep::factory()->for($campaign)->create(['wait_days' => 5, 'subject' => 'Again']);

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])->callAction('duplicate');

    $copy = Campaign::query()->whereKeyNot($campaign->id)->sole();
    expect($copy)->status->toBe(CampaignStatus::Draft)->archived_at->toBeNull();
    expect($copy->steps->sole())->wait_days->toBe(5)->subject->toBe('Again');
});

it('archives a finished campaign and hides it from the list until asked for', function () {
    $this->actingAs(User::factory()->sender()->create());
    $finished = Campaign::factory()->status(CampaignStatus::Completed)->create();
    $sending = Campaign::factory()->prepared()->create();

    Livewire::test(ViewCampaign::class, ['record' => $finished->getRouteKey()])->callAction('archive');
    Livewire::test(ViewCampaign::class, ['record' => $sending->getRouteKey()])->assertActionHidden('archive');

    expect($finished->refresh()->archived_at)->not->toBeNull();
    Livewire::test(ListCampaigns::class)
        ->assertCanNotSeeTableRecords([$finished])
        ->assertCanSeeTableRecords([$sending])
        ->filterTable('archived', true)
        ->assertCanSeeTableRecords([$finished]);
});

it('lists prospects with their place in the sequence and stops one on request', function () {
    $this->actingAs(User::factory()->sender()->create());
    $campaign = Campaign::factory()->prepared()->create();
    CampaignStep::factory()->for($campaign)->create();
    $prospect = CampaignProspect::query()->create(['campaign_id' => $campaign->id, 'contact_id' => Contact::factory()->create()->id, 'current_step' => 1, 'next_step_due_at' => now()->addDay()]);

    Livewire::test(ProspectsRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => ViewCampaign::class])
        ->assertCanSeeTableRecords([$prospect])
        ->assertTableColumnFormattedStateSet('current_step', '1 of 2', $prospect)
        ->callAction(TestAction::make('stop')->table($prospect));

    expect($prospect->refresh())->status->toBe(SequenceStatus::Stopped)->finish_reason->toStartWith('Stopped by');
});

it('lets Guest Viewers see prospects but not stop them', function () {
    $this->actingAs(User::factory()->create());
    $campaign = Campaign::factory()->prepared()->create();
    $prospect = CampaignProspect::query()->create(['campaign_id' => $campaign->id, 'contact_id' => Contact::factory()->create()->id]);

    Livewire::test(ProspectsRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => ViewCampaign::class])
        ->assertCanSeeTableRecords([$prospect])
        ->assertActionHidden(TestAction::make('stop')->table($prospect));
});

it('lets follow-ups swap places, as the form saves a reordered list one row at a time', function () {
    $campaign = Campaign::factory()->create();
    $first = CampaignStep::factory()->for($campaign)->create(['position' => 1, 'wait_days' => 2]);
    $second = CampaignStep::factory()->for($campaign)->create(['position' => 2, 'wait_days' => 5]);

    $first->update(['position' => 2]);
    $second->update(['position' => 1]);

    expect($campaign->steps()->pluck('wait_days')->all())->toBe([5, 2]);
});
