<?php

namespace App\Filament\Resources\WarmupPlans\Schemas;

use App\Enums\WarmupStatus;
use App\Models\WarmupPlan;
use App\Services\Warmup\WarmupAdvisor;
use App\Services\Warmup\WarmupAssessment;
use App\Services\Warmup\WarmupScheduler;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;
use WeakMap;

class WarmupPlanInfolist
{
    /**
     * @var WeakMap<WarmupPlan, WarmupAssessment>|null
     */
    private static ?WeakMap $assessments = null;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Callout::make('This plan is paused')
                    ->description(fn (WarmupPlan $record): string => ($record->paused_reason ?? 'Paused by an administrator.').' Email covered by this plan waits until it is resumed.')
                    ->danger()
                    ->visible(fn (WarmupPlan $record): bool => $record->status === WarmupStatus::Paused)
                    ->columnSpanFull(),
                Callout::make('Awaiting approval')
                    ->description('The plan does not limit sending until an administrator approves it.')
                    ->info()
                    ->visible(fn (WarmupPlan $record): bool => $record->status === WarmupStatus::Draft)
                    ->columnSpanFull(),
                Section::make('Today')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('today')
                            ->label('Sent today / allowed')
                            ->state(fn (WarmupPlan $record): string => Number::format(static::assessment($record)->sentToday).' / '.Number::format($record->todaysLimit())),
                        TextEntry::make('ceiling')
                            ->label('Target')
                            ->state(fn (WarmupPlan $record): string => Number::format($record->ceiling()).' per day'.($record->smtpProvider?->daily_limit !== null && $record->smtpProvider->daily_limit <= $record->ceiling() ? ' (provider limit)' : '')),
                        TextEntry::make('phase')
                            ->label('Phase')
                            ->state(fn (WarmupPlan $record): string => static::phase($record)),
                        TextEntry::make('sendingDomain.domain')
                            ->label('Domain')
                            ->placeholder('All email through the provider'),
                    ]),
                Section::make('Last 7 days')
                    ->description('Opens and clicks are not used: privacy proxies and security scanners make them unreliable.')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('accepted')
                            ->label('Accepted by provider')
                            ->state(fn (WarmupPlan $record): string => Number::format(static::assessment($record)->accepted)),
                        TextEntry::make('hard_bounce_rate')
                            ->label('Hard bounce rate')
                            ->state(fn (WarmupPlan $record): string => static::assessment($record)->hardBounceRate.'%')
                            ->color(fn (WarmupPlan $record): string => static::assessment($record)->hardBounceRate > $record->max_hard_bounce_rate ? 'danger' : 'success')
                            ->helperText(fn (WarmupPlan $record): string => "Limit {$record->max_hard_bounce_rate}%"),
                        TextEntry::make('complaint_rate')
                            ->label('Complaint rate')
                            ->state(fn (WarmupPlan $record): string => static::assessment($record)->complaintRate.'%')
                            ->color(fn (WarmupPlan $record): string => static::assessment($record)->complaintRate > $record->max_complaint_rate ? 'danger' : 'success')
                            ->helperText(fn (WarmupPlan $record): string => "Limit {$record->max_complaint_rate}%"),
                        TextEntry::make('soft_bounce_rate')
                            ->label('Soft bounce rate')
                            ->state(fn (WarmupPlan $record): string => static::assessment($record)->softBounceRate.'%'),
                        TextEntry::make('unsubscribe_rate')
                            ->label('Unsubscribe rate')
                            ->state(fn (WarmupPlan $record): string => static::assessment($record)->unsubscribeRate.'%'),
                        TextEntry::make('deferrals')
                            ->label('Throttling events')
                            ->state(fn (WarmupPlan $record): string => Number::format(static::assessment($record)->deferrals).' deferrals · '.Number::format(static::assessment($record)->failures).' failures'),
                    ]),
                Section::make('Recommendation')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('recommendation')
                            ->hiddenLabel()
                            ->state(fn (WarmupPlan $record): string => static::assessment($record)->recommendation->getLabel())
                            ->badge()
                            ->color(fn (WarmupPlan $record): string => static::assessment($record)->recommendation->getColor())
                            ->helperText(fn (WarmupPlan $record): string => static::assessment($record)->recommendation->getDescription().' '.implode(' ', static::assessment($record)->reasons)),
                        TextEntry::make('last_evaluated_at')
                            ->label('Last automatic review')
                            ->since()
                            ->placeholder('Not yet. Reviews run daily at 00:05.'),
                    ]),
                Section::make('Proposed schedule')
                    ->description('Each step happens only when the previous one was healthy and actually used. Never above the provider limit.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('schedule')
                            ->hiddenLabel()
                            ->state(fn (WarmupPlan $record): array => array_map(fn (array $step): array => [
                                'step' => $step['step'],
                                'date' => $step['date']->toFormattedDayDateString(),
                                'daily_volume' => Number::format($step['daily_volume']),
                            ], app(WarmupScheduler::class)->schedule($record)))
                            ->table([
                                TableColumn::make('Step'),
                                TableColumn::make('Earliest date'),
                                TableColumn::make('Emails per day'),
                            ])
                            ->schema([
                                TextEntry::make('step'),
                                TextEntry::make('date'),
                                TextEntry::make('daily_volume'),
                            ]),
                    ]),
            ]);
    }

    private static function assessment(WarmupPlan $plan): WarmupAssessment
    {
        self::$assessments ??= new WeakMap;

        return self::$assessments[$plan] ??= app(WarmupAdvisor::class)->assess($plan);
    }

    private static function phase(WarmupPlan $plan): string
    {
        $schedule = app(WarmupScheduler::class)->schedule($plan);
        $current = $plan->todaysLimit();
        $step = collect($schedule)->filter(fn (array $item): bool => $item['daily_volume'] <= $current)->count();

        return 'Step '.max(1, $step).' of '.count($schedule);
    }
}
