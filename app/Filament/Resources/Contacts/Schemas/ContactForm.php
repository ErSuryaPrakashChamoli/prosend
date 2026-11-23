<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Enums\ConsentStatus;
use App\Enums\ContactStage;
use App\Models\Contact;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ContactForm
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
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->columnSpanFull(),
                        TextInput::make('first_name')
                            ->maxLength(255),
                        TextInput::make('last_name')
                            ->maxLength(255),
                        TextInput::make('company')
                            ->maxLength(255),
                        TextInput::make('job_title')
                            ->maxLength(255),
                        TextInput::make('industry')
                            ->maxLength(255),
                        TextInput::make('website')
                            ->placeholder('example.com')
                            ->maxLength(255)
                            // Checked as it will be saved: the model adds https:// when there is no scheme.
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (filled($value) && ! Str::isUrl(Contact::normalizeWebsite($value), ['http', 'https'])) {
                                    $fail('Enter a web address, such as example.com or https://example.com.');
                                }
                            }),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('source')
                            ->placeholder('For example: website signup')
                            ->maxLength(255),
                    ]),
                Section::make('Consent')
                    ->description('Marketing campaigns only reach contacts with recorded consent.')
                    ->columnSpan(1)
                    ->schema([
                        Radio::make('consent_status')
                            ->label('Status')
                            ->options(ConsentStatus::class)
                            ->default(ConsentStatus::Unknown)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                if (($state instanceof ConsentStatus ? $state : ConsentStatus::tryFrom((string) $state)) === ConsentStatus::Granted && blank($get('consented_at'))) {
                                    $set('consented_at', now()->toDateTimeString());
                                }
                            }),
                        TextInput::make('consent_source')
                            ->label('How consent was given')
                            ->placeholder('For example: newsletter form')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => static::isGranted($get('consent_status'))),
                        DateTimePicker::make('consented_at')
                            ->label('Consent recorded at')
                            ->maxDate(now()->addMinute())
                            ->required(fn (Get $get): bool => static::isGranted($get('consent_status'))),
                    ]),
                Section::make('Lists and tags')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Select::make('lists')
                            ->relationship('lists', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->createOptionForm([
                                TextInput::make('name')->required()->maxLength(255)->unique('contact_lists', 'name'),
                            ]),
                        TagsInput::make('tags')
                            ->placeholder('Add a tag')
                            ->nestedRecursiveRules(['string', 'max:50']),
                    ]),
                Section::make('Outreach')
                    ->columnSpan(1)
                    ->schema([
                        Select::make('stage')
                            ->options(ContactStage::class)
                            ->default(ContactStage::New)
                            ->required(),
                        Select::make('owner_id')
                            ->label('Owner')
                            ->relationship('owner', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Unassigned'),
                        DateTimePicker::make('last_replied_at')
                            ->label('Last reply')
                            ->maxDate(now()->addMinute())
                            ->helperText('ProSend cannot read replies, so record them here.'),
                    ]),
                Section::make('Notes')
                    ->columnSpan(2)
                    ->schema([
                        Textarea::make('notes')
                            ->hiddenLabel()
                            ->rows(4)
                            ->maxLength(10000),
                    ]),
                Section::make('Custom attributes')
                    ->columnSpan(2)
                    ->collapsed()
                    ->schema([
                        KeyValue::make('custom_attributes')
                            ->hiddenLabel()
                            ->keyLabel('Attribute')
                            ->valueLabel('Value'),
                    ]),
            ]);
    }

    private static function isGranted(mixed $state): bool
    {
        return ($state instanceof ConsentStatus ? $state : ConsentStatus::tryFrom((string) $state)) === ConsentStatus::Granted;
    }
}
