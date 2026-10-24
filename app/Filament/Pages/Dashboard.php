<?php

namespace App\Filament\Pages;

use App\Enums\EmailPurpose;
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
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(['md' => 3, 'xl' => 6])
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('From')
                            ->default(now()->subDays(29)->toDateString())
                            ->maxDate(now()),
                        DatePicker::make('end_date')
                            ->label('To')
                            ->default(now()->toDateString())
                            ->maxDate(now()),
                        Select::make('purpose')
                            ->label('Email type')
                            ->options(EmailPurpose::class)
                            ->placeholder('All types'),
                        Select::make('smtp_provider_id')
                            ->label('Provider')
                            ->options(fn (): array => SmtpProvider::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder('All providers'),
                        Select::make('campaign_id')
                            ->label('Campaign')
                            ->options(fn (): array => Campaign::query()->latest()->limit(200)->pluck('name', 'id')->all())
                            ->searchable()
                            ->placeholder('All email'),
                        Select::make('sending_domain')
                            ->label('Sending domain')
                            ->options(fn (): array => SenderIdentity::query()->pluck('email')
                                ->map(fn (string $email): string => str($email)->after('@')->toString())
                                ->unique()
                                ->sort()
                                ->mapWithKeys(fn (string $domain): array => [$domain => $domain])
                                ->all())
                            ->placeholder('All domains'),
                    ]),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            DeliveryStatsOverview::class,
            SendingVolumeChart::class,
            DeliveryOutcomeChart::class,
            BounceRateTrendChart::class,
            VolumeByDomainChart::class,
            ProviderPerformanceTable::class,
            CampaignPerformanceTable::class,
            OperationsOverview::class,
            QueueThroughputChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
