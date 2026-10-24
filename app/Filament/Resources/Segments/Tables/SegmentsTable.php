<?php

namespace App\Filament\Resources\Segments\Tables;

use App\Models\Segment;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SegmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Segment $record): ?string => $record->description)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('rules')
                    ->label('Rules')
                    ->state(fn (Segment $record): string => count($record->rules ?? []).' '.($record->match->value === 'all' ? 'all must match' : 'any can match')),
                TextColumn::make('updated_at')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No segments')
            ->emptyStateDescription('Segments are audiences defined by rules, such as "consented contacts at example.com who opened in the last 30 days".')
            ->emptyStateIcon(Heroicon::OutlinedFunnel);
    }
}
