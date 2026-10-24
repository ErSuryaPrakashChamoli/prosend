<?php

namespace App\Filament\Resources\EmailTemplates\Schemas;

use App\Models\EmailTemplate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmailTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Template')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('category'),
                        TextEntry::make('purpose')
                            ->label('Email type')
                            ->badge(),
                        TextEntry::make('subject')
                            ->columnSpanFull(),
                        TextEntry::make('used_variables')
                            ->label('Variables used')
                            ->state(fn (EmailTemplate $record): array => $record->usedVariables())
                            ->badge()
                            ->color('gray')
                            ->placeholder('None'),
                        TextEntry::make('required_variables')
                            ->label('Required variables')
                            ->badge()
                            ->color('warning')
                            ->placeholder('None'),
                    ]),
                Section::make('History')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('version')
                            ->prefix('Version '),
                        TextEntry::make('creator.name')
                            ->label('Created by')
                            ->placeholder('—'),
                        TextEntry::make('updater.name')
                            ->label('Last updated by')
                            ->placeholder('—'),
                        TextEntry::make('updated_at')
                            ->label('Last updated')
                            ->since(),
                        TextEntry::make('campaigns_count')
                            ->label('Used by campaigns')
                            ->state(fn (EmailTemplate $record): int => $record->campaigns()->count()),
                    ]),
            ]);
    }
}
