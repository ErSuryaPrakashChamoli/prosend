<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\EmailMetrics;
use Filament\Widgets\ChartWidget;

class QueueThroughputChart extends ChartWidget
{
    protected static ?int $sort = 8;

    protected ?string $heading = 'Queue throughput';

    protected ?string $description = 'Emails handed to providers per hour over the last 24 hours.';

    protected ?string $maxHeight = '220px';

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $hours = app(EmailMetrics::class)->acceptedPerHour();

        return [
            'datasets' => [
                ['label' => 'Accepted per hour', 'data' => array_values($hours), 'backgroundColor' => '#0ea5e9'],
            ],
            'labels' => array_keys($hours),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]]];
    }
}
