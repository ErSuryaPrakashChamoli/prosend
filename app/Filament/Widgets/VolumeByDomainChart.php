<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\EmailMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class VolumeByDomainChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Volume by sending domain';

    protected ?string $description = 'Email accepted by the provider in the period.';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $domains = array_slice(app(EmailMetrics::class)->acceptedByDomain($this->pageFilters), 0, 10, preserve_keys: true);

        return [
            'datasets' => [
                ['label' => 'Accepted', 'data' => array_values($domains), 'backgroundColor' => '#6366f1'],
            ],
            'labels' => array_keys($domains),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]]];
    }
}
