<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\EmailMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

class SendingVolumeChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Sending volume';

    protected ?string $description = 'Per day, by the date each email was created.';

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $days = app(EmailMetrics::class)->daily($this->pageFilters);

        return [
            'datasets' => [
                ['label' => 'Submitted', 'data' => array_column($days, 'submitted'), 'borderColor' => '#a1a1aa', 'backgroundColor' => '#a1a1aa'],
                ['label' => 'Accepted by provider', 'data' => array_column($days, 'accepted'), 'borderColor' => '#6366f1', 'backgroundColor' => '#6366f1'],
                ['label' => 'Confirmed delivered', 'data' => array_column($days, 'delivered'), 'borderColor' => '#16a34a', 'backgroundColor' => '#16a34a'],
                ['label' => 'Failed or bounced', 'data' => array_column($days, 'problems'), 'borderColor' => '#dc2626', 'backgroundColor' => '#dc2626'],
            ],
            'labels' => array_map(fn (string $day): string => Carbon::parse($day)->format('M j'), array_keys($days)),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
