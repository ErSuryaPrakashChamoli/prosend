<?php

namespace App\Filament\Resources\ContactLists\RelationManagers;

use App\Models\Contact;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Members';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('email')
                    ->label('Email address')
                    ->description(fn (Contact $record): ?string => $record->full_name)
                    ->searchable(['email', 'first_name', 'last_name']),
                TextColumn::make('consent_status')
                    ->label('Consent')
                    ->badge(),
                TextColumn::make('unsubscribed_at')
                    ->label('Unsubscribed')
                    ->since()
                    ->placeholder('—'),
                TextColumn::make('pivot.created_at')
                    ->label('Added to list')
                    ->dateTime(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add contacts')
                    ->multiple()
                    ->recordSelectSearchColumns(['email', 'first_name', 'last_name']),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label('Remove'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label('Remove from list'),
                ]),
            ]);
    }
}
