<?php

namespace App\Filament\Resources\Campaigns\Widgets;

use App\Enums\MessageStatus;
use App\Models\Campaign;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class CampaignProgress extends StatsOverviewWidget
{
    public ?Campaign $record = null;

    protected ?string $heading = 'Delivery progress';

    protected ?string $description = 'Accepted means the provider took the message. Only delivery events from the provider confirm delivery.';

    protected function getPollingInterval(): ?string
    {
        return $this->record?->status->isFinished() ? null : '10s';
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $counts = $this->record?->messageCountsByStatus() ?? [];
        $count = fn (MessageStatus ...$statuses): int => array_sum(array_map(fn (MessageStatus $status): int => $counts[$status->value] ?? 0, $statuses));

        $total = array_sum($counts);
        $accepted = $count(...MessageStatus::acceptedByProvider());
        $delivered = $count(MessageStatus::Delivered);

        return [
            Stat::make('Recipients', Number::format($total))
                ->description(Number::format($count(MessageStatus::Queued, MessageStatus::Deferred, MessageStatus::Sending)).' waiting to send'),
            Stat::make('Accepted by provider', Number::format($accepted))
                ->description($total > 0 ? Number::percentage($accepted / $total * 100, 1).' of recipients' : 'Nothing sent yet')
                ->color('primary'),
            Stat::make('Confirmed delivered', Number::format($delivered))
                ->description($accepted > 0 ? Number::percentage($delivered / $accepted * 100, 1).' of accepted' : 'Requires provider webhooks')
                ->color('success'),
            Stat::make('Bounced or complained', Number::format($count(MessageStatus::Bounced, MessageStatus::SoftBounced, MessageStatus::Complained)))
                ->description(Number::format($count(MessageStatus::Bounced)).' hard, '.Number::format($count(MessageStatus::SoftBounced)).' soft, '.Number::format($count(MessageStatus::Complained)).' complaints')
                ->color('danger'),
            Stat::make('Failed or unknown', Number::format($count(MessageStatus::Failed, MessageStatus::Unknown)))
                ->description(Number::format($count(MessageStatus::Unknown)).' with unknown outcome')
                ->color('warning'),
            Stat::make('Skipped', Number::format($count(MessageStatus::Suppressed, MessageStatus::Cancelled)))
                ->description('Suppressed at send time or cancelled'),
        ];
    }
}
