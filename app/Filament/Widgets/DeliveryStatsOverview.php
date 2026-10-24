<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\EmailMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class DeliveryStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Delivery';

    protected ?string $description = 'Emails created in the selected period. "Accepted" means the provider took the email; only provider delivery events confirm delivery.';

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $metrics = app(EmailMetrics::class)->summary($this->pageFilters);
        $rate = fn (int $count, int $of): string => $of > 0 ? Number::percentage($count / $of * 100, 1) : '—';

        return [
            Stat::make('Submitted', Number::format($metrics['submitted']))
                ->description(Number::format($metrics['suppressed']).' skipped by suppression checks'),
            Stat::make('Accepted by provider', Number::format($metrics['accepted']))
                ->description($rate($metrics['accepted'], $metrics['submitted']).' of submitted')
                ->color('primary'),
            Stat::make('Confirmed delivered', Number::format($metrics['delivered']))
                ->description('Delivery rate '.$rate($metrics['delivered'], $metrics['accepted']).' of accepted')
                ->color('success'),
            Stat::make('Hard bounces', Number::format($metrics['hard_bounces']))
                ->description('Bounce rate '.$rate($metrics['hard_bounces'], $metrics['accepted']).' · '.Number::format($metrics['soft_bounces']).' soft bounces')
                ->color($metrics['accepted'] > 0 && $metrics['hard_bounces'] / $metrics['accepted'] > 0.02 ? 'danger' : 'gray'),
            Stat::make('Spam complaints', Number::format($metrics['complaints']))
                ->description('Complaint rate '.$rate($metrics['complaints'], $metrics['accepted']).' · keep below 0.1%')
                ->color($metrics['accepted'] > 0 && $metrics['complaints'] / $metrics['accepted'] > 0.001 ? 'danger' : 'gray'),
            Stat::make('Unsubscribes', Number::format($metrics['unsubscribes']))
                ->description($rate($metrics['unsubscribes'], $metrics['accepted']).' of accepted'),
            Stat::make('Failed', Number::format($metrics['failed']))
                ->description(Number::format($metrics['unknown']).' with unknown outcome')
                ->color($metrics['failed'] + $metrics['unknown'] > 0 ? 'warning' : 'gray'),
            Stat::make('Opened (approximate)', Number::format($metrics['opened']))
                ->description(Number::format($metrics['clicked']).' clicked · privacy proxies and scanners inflate these'),
        ];
    }
}
