<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\EmailMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

class BounceRateTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Bounce and complaint rates';

    protected ?string $description = 'Share of accepted email per day. Keep hard bounces under 2% and complaints under 0.1%.';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected function getData(): array
    {
        $days = app(EmailMetrics::class)->daily($this->pageFilters);
        $rate = fn (int $count, int $accepted): ?float => $accepted > 0 ? round($count / $accepted * 100, 2) : null;

        return [
            'datasets' => [
                [
                    'label' => 'Hard bounce rate (%)',
                    'data' => array_values(array_map(fn (array $day): ?float => $rate($day['hard_bounces'], $day['accepted']), $days)),
                    'borderColor' => '#dc2626',
                    'backgroundColor' => '#dc2626',
                    'spanGaps' => true,
                ],
                [
                    'label' => 'Complaint rate (%)',
                    'data' => array_values(array_map(fn (array $day): ?float => $rate($day['complaints'], $day['accepted']), $days)),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => '#f59e0b',
                    'spanGaps' => true,
                ],
            ],
            'labels' => array_map(fn (string $day): string => Carbon::parse($day)->format('M j'), array_keys($days)),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
