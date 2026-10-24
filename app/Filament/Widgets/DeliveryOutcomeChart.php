<?php

namespace App\Filament\Widgets;

use App\Enums\MessageStatus;
use App\Services\Analytics\EmailMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class DeliveryOutcomeChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Outcomes';

    protected ?string $description = 'Current status of emails in the period.';

    protected ?string $maxHeight = '280px';

    /**
     * @var array<string, string>
     */
    private const COLORS = [
        'success' => '#16a34a',
        'primary' => '#6366f1',
        'info' => '#0ea5e9',
        'warning' => '#f59e0b',
        'danger' => '#dc2626',
        'gray' => '#a1a1aa',
    ];

    protected function getData(): array
    {
        $breakdown = array_filter(app(EmailMetrics::class)->statusBreakdown($this->pageFilters));
        $statuses = array_map(fn (string $status): MessageStatus => MessageStatus::from($status), array_keys($breakdown));

        return [
            'datasets' => [
                [
                    'data' => array_values($breakdown),
                    'backgroundColor' => array_map(fn (MessageStatus $status): string => self::COLORS[$status->getColor()], $statuses),
                ],
            ],
            'labels' => array_map(fn (MessageStatus $status): string => $status->getLabel(), $statuses),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ];
    }
}
