<?php

namespace App\Filament\Widgets;

use App\Enums\BounceType;
use App\Enums\MessageStatus;
use App\Models\SmtpProvider;
use App\Services\Analytics\EmailMetrics;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class ProviderPerformanceTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $metrics = app(EmailMetrics::class);
        [$start, $end] = $metrics->period($this->pageFilters);
        $inPeriod = fn (Builder $messages): Builder => $metrics->filter($messages->whereBetween('email_messages.created_at', [$start, $end]), $this->pageFilters);

        return $table
            ->heading('Provider performance')
            ->description('For email created in the selected period, with the dashboard filters applied.')
            ->query(fn (): Builder => SmtpProvider::query()->when($this->pageFilters['smtp_provider_id'] ?? null, fn (Builder $providers, int|string $id) => $providers->whereKey($id))->withCount([
                'emailMessages as accepted_count' => fn (Builder $messages) => $inPeriod($messages)->whereIn('status', MessageStatus::acceptedByProvider()),
                'emailMessages as delivered_count' => fn (Builder $messages) => $inPeriod($messages)->whereNotNull('delivered_at'),
                'emailMessages as hard_bounce_count' => fn (Builder $messages) => $inPeriod($messages)->where('bounce_type', BounceType::Hard),
                'emailMessages as complaint_count' => fn (Builder $messages) => $inPeriod($messages)->where('status', MessageStatus::Complained),
                'emailMessages as failed_count' => fn (Builder $messages) => $inPeriod($messages)->whereIn('status', [MessageStatus::Failed, MessageStatus::Unknown]),
            ]))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->description(fn (SmtpProvider $record): string => $record->type->getLabel()),
                TextColumn::make('health_status')
                    ->label('Health')
                    ->badge(),
                TextColumn::make('accepted_count')
                    ->label('Accepted')
                    ->numeric(),
                TextColumn::make('delivery_rate')
                    ->label('Delivered')
                    ->state(fn (SmtpProvider $record): string => $this->rate($record->delivered_count, $record->accepted_count)),
                TextColumn::make('bounce_rate')
                    ->label('Hard bounces')
                    ->state(fn (SmtpProvider $record): string => $this->rate($record->hard_bounce_count, $record->accepted_count)),
                TextColumn::make('complaint_rate')
                    ->label('Complaints')
                    ->state(fn (SmtpProvider $record): string => $this->rate($record->complaint_count, $record->accepted_count, precision: 2)),
                TextColumn::make('failed_count')
                    ->label('Failed or unknown')
                    ->numeric(),
            ]);
    }

    private function rate(int $count, int $of, int $precision = 1): string
    {
        return $of > 0 ? Number::percentage($count / $of * 100, $precision) : '—';
    }
}
