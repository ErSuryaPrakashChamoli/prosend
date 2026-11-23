<?php

use App\Enums\CampaignStatus;
use App\Enums\ContactStage;
use App\Enums\SuppressionReason;
use App\Filament\Widgets\AudienceOverview;
use App\Filament\Widgets\BounceRateTrendChart;
use App\Filament\Widgets\CampaignPerformanceTable;
use App\Filament\Widgets\DeliveryOutcomeChart;
use App\Filament\Widgets\DeliveryStatsOverview;
use App\Filament\Widgets\OperationsOverview;
use App\Filament\Widgets\ProviderPerformanceTable;
use App\Filament\Widgets\QueueThroughputChart;
use App\Filament\Widgets\SendingVolumeChart;
use App\Filament\Widgets\VolumeByDomainChart;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\Suppression;
use App\Models\User;
use Livewire\Livewire;

it('renders each dashboard widget with real data', function (string $widget) {
    $this->actingAs(User::factory()->admin()->create());
    $campaign = Campaign::factory()->prepared()->create();
    EmailMessage::factory()->for($campaign)->accepted()->count(2)->create();

    Livewire::test($widget, ['pageFilters' => ['start_date' => now()->subWeek()->toDateString(), 'end_date' => now()->toDateString()]])
        ->assertOk();
})->with([
    DeliveryStatsOverview::class,
    SendingVolumeChart::class,
    DeliveryOutcomeChart::class,
    BounceRateTrendChart::class,
    VolumeByDomainChart::class,
    ProviderPerformanceTable::class,
    CampaignPerformanceTable::class,
    OperationsOverview::class,
    QueueThroughputChart::class,
    AudienceOverview::class,
]);

it('lists the campaign in the performance table', function () {
    $this->actingAs(User::factory()->admin()->create());
    $campaign = Campaign::factory()->prepared()->create(['name' => 'Autumn news']);
    EmailMessage::factory()->for($campaign)->accepted()->create();

    Livewire::test(CampaignPerformanceTable::class, ['pageFilters' => []])
        ->assertCanSeeTableRecords([$campaign]);
});

it('counts contacts, marketing eligibility, suppressions, and campaigns', function () {
    $this->actingAs(User::factory()->admin()->create());
    Contact::factory()->consented()->count(2)->create(['stage' => ContactStage::Interested]);
    Contact::factory()->consented()->unsubscribed()->create(['stage' => ContactStage::Converted]);
    $suppressed = Contact::factory()->consented()->create();
    Suppression::factory()->create(['email' => $suppressed->email, 'reason' => SuppressionReason::HardBounce]);
    Contact::factory()->create();
    Campaign::factory()->count(3)->create();
    Campaign::factory()->create(['status' => CampaignStatus::Scheduled]);

    Livewire::test(AudienceOverview::class)
        ->assertSeeInOrder(['Contacts', '5', '2 interested · 1 converted'])
        ->assertSeeInOrder(['Eligible for marketing', '2'])
        ->assertSeeInOrder(['Suppressed addresses', '1', '1 contact unsubscribed'])
        ->assertSeeInOrder(['Campaigns', '4', '3 drafts · 1 scheduled · 0 sending']);
});
