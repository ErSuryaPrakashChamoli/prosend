<?php

namespace App\Filament\Resources\SmtpProviders\Tables;

use App\Enums\ProviderType;
use App\Filament\Resources\SmtpProviders\SmtpProviderActions;
use App\Models\SmtpProvider;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class SmtpProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'emailMessages as accepted_today_count' => fn (Builder $messages) => $messages->where(fn (Builder $today) => $today
                    ->where('accepted_at', '>=', now()->startOfDay())
                    ->orWhere('captured_at', '>=', now()->startOfDay())),
            ]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (SmtpProvider $record): string => $record->type->getLabel())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('delivery_mode')
                    ->label('Mode')
                    ->state(fn (SmtpProvider $record): string => $record->isSandbox() ? 'Sandbox' : 'Live')
                    ->badge()
                    ->color(fn (SmtpProvider $record): string => $record->isSandbox() ? 'warning' : 'success')
                    ->tooltip(fn (SmtpProvider $record): ?string => $record->isSandbox() ? 'Email is captured for inspection and never delivered.' : null),
                TextColumn::make('host')
                    ->formatStateUsing(fn (SmtpProvider $record): string => "{$record->host}:{$record->port}"),
                TextColumn::make('health_status')
                    ->label('Health')
                    ->badge()
                    ->tooltip(fn (SmtpProvider $record): ?string => $record->last_error),
                IconColumn::make('is_enabled')
                    ->label('Enabled')
                    ->boolean(),
                TextColumn::make('accepted_today_count')
                    ->label('Sent today')
                    ->formatStateUsing(fn (SmtpProvider $record, int $state): string => $record->daily_limit ? "{$state} / {$record->daily_limit}" : (string) $state),
                TextColumn::make('limits')
                    ->state(fn (SmtpProvider $record): string => implode(' · ', array_filter([
                        $record->daily_limit ? Number::format($record->daily_limit).'/day' : null,
                        $record->monthly_limit ? Number::format($record->monthly_limit).'/month' : null,
                        $record->max_per_minute ? Number::format($record->max_per_minute).'/min' : null,
                    ])) ?: 'No limits')
                    ->toggleable(),
                TextColumn::make('last_checked_at')
                    ->label('Last checked')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(ProviderType::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    SmtpProviderActions::testConnection(),
                    SmtpProviderActions::toggleEnabled(),
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading('No SMTP providers')
            ->emptyStateDescription('Add the SMTP details of Brevo, Mailtrap, or another provider to start sending.')
            ->emptyStateIcon(Heroicon::OutlinedServerStack);
    }
}
