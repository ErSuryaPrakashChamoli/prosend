<?php

namespace App\Filament\Resources\SmtpProviders\Tables;

use App\Filament\Resources\SmtpProviders\SmtpProviderActions;
use App\Models\SmtpProvider;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SmtpProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'emailMessages as accepted_today_count' => fn (Builder $messages) => $messages->where('accepted_at', '>=', now()->startOfDay()),
            ]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (SmtpProvider $record): string => $record->type->getLabel())
                    ->searchable()
                    ->sortable(),
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
                TextColumn::make('last_checked_at')
                    ->label('Last checked')
                    ->since()
                    ->placeholder('Never'),
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
            ->emptyStateDescription('Add your Brevo SMTP details to start sending.')
            ->emptyStateIcon(Heroicon::OutlinedServerStack);
    }
}
