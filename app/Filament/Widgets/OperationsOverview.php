<?php

namespace App\Filament\Widgets;

use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Models\Campaign;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class OperationsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 9;

    protected ?string $heading = 'Right now';

    protected ?string $pollingInterval = '30s';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $providers = SmtpProvider::query()->enabled()->get();
        $limitedProviders = $providers->whereNotNull('daily_limit');
        $quotaUsed = $limitedProviders->sum(fn (SmtpProvider $provider): int => $provider->acceptedCountSince(now()->startOfDay()));
        $quotaTotal = $limitedProviders->sum('daily_limit');

        return [
            Stat::make('Waiting to send', Number::format(EmailMessage::query()->whereIn('status', MessageStatus::inFlight())->count()))
                ->description(Number::format(DB::table('jobs')->count()).' jobs in the queue'),
            Stat::make('Scheduled campaigns', Number::format(Campaign::query()->where('status', CampaignStatus::Scheduled)->count()))
                ->description(Number::format(Campaign::query()->where('status', CampaignStatus::Sending)->count()).' sending now'),
            Stat::make('Failed queue jobs', Number::format(DB::table('failed_jobs')->count()))
                ->color(DB::table('failed_jobs')->exists() ? 'danger' : 'gray'),
            Stat::make('Active providers', Number::format($providers->count()))
                ->description($quotaTotal > 0 ? Number::format($quotaUsed).' of '.Number::format($quotaTotal).' daily sends used' : 'No daily limits configured'),
            Stat::make('Verified senders', Number::format(SenderIdentity::query()->whereNotNull('verified_at')->count()).' of '.Number::format(SenderIdentity::query()->count()))
                ->description(config('prosend.require_verified_senders') ? 'Verification is required to send' : 'Verification not enforced in this environment'),
        ];
    }
}
