<?php

namespace App\Filament\Resources\AutomationRules\RelationManagers;

use App\Models\AutomationRun;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rule's execution log: what triggered each run and what each action did.
 */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Execution log';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('contact'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime(),
                TextColumn::make('contact.email')
                    ->label('Prospect')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AutomationRun::SUCCEEDED => 'success',
                        AutomationRun::FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('log')
                    ->label('What happened')
                    ->state(fn (AutomationRun $record): string => collect($record->log ?? [])->pluck('result')->implode(' · '))
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([AutomationRun::SUCCEEDED => 'Succeeded', AutomationRun::SKIPPED => 'Conditions not met', AutomationRun::FAILED => 'An action failed']),
            ]);
    }
}
