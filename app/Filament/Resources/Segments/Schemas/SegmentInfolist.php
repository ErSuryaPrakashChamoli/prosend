<?php

namespace App\Filament\Resources\Segments\Schemas;

use App\Enums\EmailPurpose;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Segment;
use App\Services\Segments\SegmentQuery;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class SegmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Segment')
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('description')
                            ->placeholder('No description'),
                        TextEntry::make('match')
                            ->label('Matching'),
                        TextEntry::make('rules')
                            ->label('Rules')
                            ->state(fn (Segment $record): array => collect($record->rules)->map(fn (array $rule): string => SegmentQuery::RULE_TYPES[$rule['type']] ?? $rule['type'])->all())
                            ->badge()
                            ->color('gray'),
                    ]),
                Section::make('Matching contacts')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('contacts_count')
                            ->label('Contacts right now')
                            ->state(fn (Segment $record): string => Number::format($record->contactsQuery()->count()))
                            ->url(fn (Segment $record): string => ContactResource::getUrl('index', ['filters' => ['segment' => ['value' => $record->getKey()]]])),
                        TextEntry::make('marketing_eligible')
                            ->label('Eligible for marketing')
                            ->state(fn (Segment $record): string => Number::format($record->contactsQuery()->eligibleFor(EmailPurpose::Marketing)->count())),
                        TextEntry::make('sample')
                            ->label('Examples')
                            ->state(fn (Segment $record): array => $record->contactsQuery()->orderBy('id')->limit(5)->pluck('email')->all())
                            ->listWithLineBreaks()
                            ->placeholder('No matching contacts'),
                    ]),
            ]);
    }
}
