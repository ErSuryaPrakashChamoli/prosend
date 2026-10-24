<?php

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
use App\Models\EmailMessage;
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
]);

it('lists the campaign in the performance table', function () {
    $this->actingAs(User::factory()->admin()->create());
    $campaign = Campaign::factory()->prepared()->create(['name' => 'Autumn news']);
    EmailMessage::factory()->for($campaign)->accepted()->create();

    Livewire::test(CampaignPerformanceTable::class, ['pageFilters' => []])
        ->assertCanSeeTableRecords([$campaign]);
});
