<?php

namespace App\Filament\Widgets;

use App\Enums\CampaignStatus;
use App\Enums\ConversationStatus;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\Conversation;
use App\Services\Analytics\OutreachReport;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The outreach results that matter most: replies, positive replies and conversions, for the dashboard's period.
 */
class OutreachOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 0;

    protected ?string $heading = 'Outreach';

    protected ?string $description = 'Emails providers accepted in the selected period. Replies come from inbound mailboxes; positive replies are conversations marked Interested or Converted.';

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $report = app(OutreachReport::class);
        $totals = $report->withRates($report->totals($this->pageFilters));
        $percent = fn (float $rate): string => Number::percentage($rate, 1);

        return [
            Stat::make('Emails sent', Number::format($totals['sent']))
                ->description(Number::format($totals['delivered']).' confirmed delivered'),
            Stat::make('Reply rate', $percent($totals['reply_rate']))
                ->description(Number::format($totals['replied']).' replies')
                ->color('success'),
            Stat::make('Positive replies', Number::format($totals['positive']))
                ->description($percent($totals['positive_rate']).' of prospects who replied · '.Number::format($totals['converted']).' converted')
                ->color('success'),
            Stat::make('Open rate (approximate)', $percent($totals['open_rate']))
                ->description('Click rate '.$percent($totals['click_rate'])),
            Stat::make('Bounce rate', $percent($totals['bounce_rate']))
                ->description('Unsubscribe rate '.$percent($totals['unsubscribe_rate']))
                ->color($totals['bounce_rate'] > 2 ? 'danger' : 'gray'),
            Stat::make('Active campaigns', Number::format(Campaign::query()->where('status', CampaignStatus::Sending)->count()))
                ->description(Number::format(CampaignProspect::query()->active()->count()).' prospects in a sequence'),
            Stat::make('Open conversations', Number::format(Conversation::query()->whereIn('status', [ConversationStatus::New, ConversationStatus::Open, ConversationStatus::FollowUp])->count()))
                ->description(Number::format(Conversation::query()->where('is_read', false)->count()).' unread'),
        ];
    }
}
