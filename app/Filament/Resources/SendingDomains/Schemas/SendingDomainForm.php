<?php

namespace App\Filament\Resources\SendingDomains\Schemas;

use App\Models\SendingDomain;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SendingDomainForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('domain')
                            ->required()
                            ->maxLength(253)
                            ->placeholder('example.com')
                            ->regex('/^@?([a-z0-9-]+\.)+[a-z]{2,}$/i')
                            ->validationMessages(['regex' => 'Enter a domain such as example.com, without https:// or a path.'])
                            ->unique(ignoreRecord: true)
                            ->helperText('The part after @ in your From address. Add each domain you send from.'),
                        Select::make('smtp_provider_id')
                            ->label('Sends through')
                            ->relationship('smtpProvider', 'name')
                            ->preload()
                            ->required(),
                        TagsInput::make('dkim_selectors')
                            ->label('DKIM selectors')
                            ->placeholder('Add a selector')
                            ->helperText('Leave empty for Brevo, which uses '.implode(', ', SendingDomain::BREVO_DKIM_SELECTORS).'. For other providers, enter the selectors they gave you.'),
                    ]),
            ]);
    }
}
