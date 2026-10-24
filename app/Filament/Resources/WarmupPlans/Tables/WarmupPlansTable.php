<?php

namespace App\Filament\Resources\WarmupPlans\Tables;

use App\Models\WarmupPlan;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class WarmupPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['smtpProvider', 'sendingDomain']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (WarmupPlan $record): string => $record->smtpProvider->name.($record->sendingDomain ? ' · '.$record->sendingDomain->domain : ''))
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('limit')
                    ->label('Daily limit')
                    ->state(fn (WarmupPlan $record): string => Number::format($record->todaysLimit()).' of '.Number::format($record->ceiling())),
                TextColumn::make('last_recommendation')
                    ->label('Recommendation')
                    ->badge()
                    ->placeholder('Not reviewed yet'),
                TextColumn::make('start_date')
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No warm-up plans')
            ->emptyStateDescription('For a new domain or provider account, start low and increase volume gradually while bounce and complaint rates stay healthy.')
            ->emptyStateIcon(Heroicon::OutlinedArrowTrendingUp);
    }
}
