<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Enums\EmailPurpose;
use App\Models\Contact;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Contact details')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('email')
                            ->label('Email address')
                            ->copyable(),
                        TextEntry::make('full_name')
                            ->label('Name')
                            ->placeholder('-'),
                        TextEntry::make('company')
                            ->placeholder('-'),
                        TextEntry::make('phone')
                            ->placeholder('-'),
                        TextEntry::make('source')
                            ->placeholder('-'),
                        TextEntry::make('lists.name')
                            ->label('Lists')
                            ->badge()
                            ->placeholder('Not in any list'),
                        TextEntry::make('tags')
                            ->badge()
                            ->color('gray')
                            ->placeholder('No tags'),
                        TextEntry::make('created_at')
                            ->label('Added')
                            ->dateTime(),
                    ]),
                Section::make('Sending eligibility')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('consent_status')
                            ->label('Consent')
                            ->badge(),
                        TextEntry::make('consent_source')
                            ->label('Consent source')
                            ->placeholder('-'),
                        TextEntry::make('consented_at')
                            ->label('Consent recorded')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('unsubscribed_at')
                            ->label('Unsubscribed')
                            ->dateTime()
                            ->placeholder('No'),
                        TextEntry::make('suppression.reason')
                            ->label('Suppression')
                            ->badge()
                            ->placeholder('Not suppressed'),
                        TextEntry::make('marketing_eligibility')
                            ->label('Marketing email')
                            ->state(fn (Contact $record): string => $record->ineligibilityReason(EmailPurpose::Marketing) === null ? 'Eligible' : 'Not eligible: '.$record->ineligibilityReason(EmailPurpose::Marketing))
                            ->color(fn (Contact $record): string => $record->ineligibilityReason(EmailPurpose::Marketing) === null ? 'success' : 'warning'),
                        TextEntry::make('transactional_eligibility')
                            ->label('Transactional email')
                            ->state(fn (Contact $record): string => $record->ineligibilityReason(EmailPurpose::Transactional) === null ? 'Eligible' : 'Not eligible: '.$record->ineligibilityReason(EmailPurpose::Transactional))
                            ->color(fn (Contact $record): string => $record->ineligibilityReason(EmailPurpose::Transactional) === null ? 'success' : 'danger'),
                    ]),
                Section::make('Custom attributes')
                    ->columnSpan(2)
                    ->visible(fn (Contact $record): bool => filled($record->custom_attributes))
                    ->schema([
                        KeyValueEntry::make('custom_attributes')
                            ->hiddenLabel(),
                    ]),
            ]);
    }
}
