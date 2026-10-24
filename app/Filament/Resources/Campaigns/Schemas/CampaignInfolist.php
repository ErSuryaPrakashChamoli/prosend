<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\AudienceType;
use App\Models\Campaign;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CampaignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Overview')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('purpose')
                            ->badge(),
                        TextEntry::make('audience')
                            ->state(fn (Campaign $record): string => match ($record->audience_type) {
                                AudienceType::AllContacts => 'All contacts',
                                AudienceType::Lists => 'Lists: '.($record->lists->pluck('name')->join(', ') ?: 'none chosen'),
                                AudienceType::SelectedContacts => $record->selectedContacts()->count().' selected contacts',
                                AudienceType::Segment => 'Segment: '.($record->segment?->name ?? 'none chosen'),
                            }),
                        TextEntry::make('emailTemplate.name')
                            ->label('Template')
                            ->placeholder('No template'),
                        TextEntry::make('required_variables')
                            ->label('Required variables')
                            ->badge()
                            ->color('warning')
                            ->placeholder('None'),
                        TextEntry::make('senderIdentity.email')
                            ->label('From')
                            ->formatStateUsing(fn (Campaign $record): string => "{$record->senderIdentity->name} <{$record->senderIdentity->email}>")
                            ->placeholder('Not chosen'),
                        TextEntry::make('subject')
                            ->placeholder('No subject yet')
                            ->columnSpanFull(),
                    ]),
                Section::make('Schedule')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('send_rate_per_minute')
                            ->label('Rate')
                            ->suffix(' emails per minute'),
                        TextEntry::make('scheduled_at')
                            ->label('Scheduled for')
                            ->dateTime()
                            ->placeholder('Sends when launched'),
                        TextEntry::make('started_at')
                            ->label('Started')
                            ->dateTime()
                            ->placeholder('Not started'),
                        TextEntry::make('completed_at')
                            ->label('Finished')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('creator.name')
                            ->label('Created by')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
