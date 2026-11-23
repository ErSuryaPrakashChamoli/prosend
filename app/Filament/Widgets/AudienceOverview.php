<?php

namespace App\Filament\Widgets;

use App\Enums\CampaignStatus;
use App\Enums\ContactStage;
use App\Enums\EmailPurpose;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Suppression;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Number;

/**
 * Audience and campaign totals as they stand now. Unlike the delivery figures, these ignore the date filters.
 */
class AudienceOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected ?string $heading = 'Audience and campaigns';

    protected ?string $description = 'Totals right now, not limited to the selected period.';

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $contactsByStage = $this->countBy(Contact::query()->toBase(), 'stage');
        $campaignsByStatus = $this->countBy(Campaign::query()->toBase(), 'status');
        $count = fn (array $counts, ContactStage|CampaignStatus $key): string => Number::format($counts[$key->value] ?? 0);
        $unsubscribed = Contact::query()->whereNotNull('unsubscribed_at')->count();

        return [
            Stat::make('Contacts', Number::format(array_sum($contactsByStage)))
                ->description($count($contactsByStage, ContactStage::Interested).' interested · '.$count($contactsByStage, ContactStage::Converted).' converted'),
            Stat::make('Eligible for marketing', Number::format(Contact::query()->eligibleFor(EmailPurpose::Marketing)->count()))
                ->description('Consent recorded, not unsubscribed or suppressed')
                ->color('success'),
            Stat::make('Suppressed addresses', Number::format(Suppression::query()->count()))
                ->description(Number::format($unsubscribed).' '.str('contact')->plural($unsubscribed).' unsubscribed'),
            Stat::make('Campaigns', Number::format(array_sum($campaignsByStatus)))
                ->description($count($campaignsByStatus, CampaignStatus::Draft).' drafts · '.$count($campaignsByStatus, CampaignStatus::Scheduled).' scheduled · '.$count($campaignsByStatus, CampaignStatus::Sending).' sending'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countBy(Builder $query, string $column): array
    {
        return $query
            ->selectRaw("{$column}, count(*) as aggregate")
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }
}
