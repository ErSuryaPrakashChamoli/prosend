<?php

namespace App\Filament\Widgets;

use App\Enums\BounceType;
use App\Enums\EmailEventType;
use App\Enums\MessageStatus;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Services\Analytics\EmailMetrics;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class CampaignPerformanceTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        [$start, $end] = app(EmailMetrics::class)->period($this->pageFilters);

        return $table
            ->heading('Campaign performance')
            ->description('Campaigns that started in the selected period, counting all of each campaign\'s email. Opens are approximate.')
            ->query(fn (): Builder => Campaign::query()
                ->whereBetween('started_at', [$start, $end])
                ->when($this->pageFilters['purpose'] ?? null, fn (Builder $campaigns, string $purpose) => $campaigns->where('purpose', $purpose))
                ->when($this->pageFilters['campaign_id'] ?? null, fn (Builder $campaigns, int|string $id) => $campaigns->whereKey($id))
                ->when($this->pageFilters['smtp_provider_id'] ?? null, fn (Builder $campaigns, int|string $id) => $campaigns->whereHas('senderIdentity', fn (Builder $sender) => $sender->where('smtp_provider_id', $id)))
                ->when($this->pageFilters['sending_domain'] ?? null, fn (Builder $campaigns, string $domain) => $campaigns->whereHas('senderIdentity', fn (Builder $sender) => $sender->where('email', 'like', '%@'.$domain)))
                ->withCount([
                    'messages',
                    'messages as accepted_count' => fn (Builder $messages) => $messages->whereIn('status', MessageStatus::acceptedByProvider()),
                    'messages as delivered_count' => fn (Builder $messages) => $messages->whereNotNull('delivered_at'),
                    'messages as hard_bounce_count' => fn (Builder $messages) => $messages->where('bounce_type', BounceType::Hard),
                    'messages as complaint_count' => fn (Builder $messages) => $messages->where('status', MessageStatus::Complained),
                    'messages as opened_count' => fn (Builder $messages) => $messages->whereHas('events', fn (Builder $events) => $events->whereIn('type', [EmailEventType::Opened, EmailEventType::Clicked])),
                ])
                ->latest('started_at'))
            ->recordUrl(fn (Campaign $record): string => CampaignResource::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Campaign $record): ?string => $record->started_at?->toFormattedDayDateString()),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('messages_count')
                    ->label('Recipients')
                    ->numeric(),
                TextColumn::make('delivery_rate')
                    ->label('Delivered')
                    ->state(fn (Campaign $record): string => $this->rate($record->delivered_count, $record->accepted_count)),
                TextColumn::make('bounce_rate')
                    ->label('Hard bounces')
                    ->state(fn (Campaign $record): string => $this->rate($record->hard_bounce_count, $record->accepted_count)),
                TextColumn::make('complaint_rate')
                    ->label('Complaints')
                    ->state(fn (Campaign $record): string => $this->rate($record->complaint_count, $record->accepted_count, precision: 2)),
                TextColumn::make('open_rate')
                    ->label('Opened (approx.)')
                    ->state(fn (Campaign $record): string => $this->rate($record->opened_count, $record->accepted_count)),
            ])
            ->emptyStateHeading('No campaigns sent in this period');
    }

    private function rate(int $count, int $of, int $precision = 1): string
    {
        return $of > 0 ? Number::percentage($count / $of * 100, $precision) : '—';
    }
}
