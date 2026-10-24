<?php

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Filament\Resources\EmailMessages\EmailMessageResource;
use App\Models\EmailMessage;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmailMessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'emailMessages';

    protected static ?string $title = 'Email history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('subject')
                    ->limit(60)
                    ->searchable(),
                TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->placeholder('Individual email'),
                TextColumn::make('purpose')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->tooltip(fn (EmailMessage $record): string => $record->status->getDescription()),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (EmailMessage $record): string => EmailMessageResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
