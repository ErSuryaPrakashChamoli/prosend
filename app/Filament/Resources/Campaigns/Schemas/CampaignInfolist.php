<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\AudienceType;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

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
                        TextEntry::make('review_note')
                            ->label('Review note')
                            ->color('warning')
                            ->visible(fn (Campaign $record): bool => filled($record->review_note))
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
                        TextEntry::make('submitter.name')
                            ->label('Submitted by')
                            ->helperText(fn (Campaign $record): ?string => $record->submitted_at?->toDayDateTimeString())
                            ->visible(fn (Campaign $record): bool => $record->submitted_at !== null),
                        TextEntry::make('approver.name')
                            ->label('Approved by')
                            ->helperText(fn (Campaign $record): ?string => $record->approved_at
                                ? $record->approved_at->toDayDateTimeString().' · '.Number::format((int) $record->approved_recipient_count).' recipients approved'
                                : null)
                            ->placeholder(fn (Campaign $record): string => $record->status === CampaignStatus::PendingApproval ? 'Waiting for approval' : 'Launched before approval was required')
                            ->visible(fn (Campaign $record): bool => $record->status !== CampaignStatus::Draft),
                    ]),
            ]);
    }
}
