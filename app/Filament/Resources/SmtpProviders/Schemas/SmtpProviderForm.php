<?php

namespace App\Filament\Resources\SmtpProviders\Schemas;

use App\Enums\ProviderType;
use App\Enums\SmtpEncryption;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SmtpProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Connection')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('For example: Brevo production'),
                        Select::make('type')
                            ->options(ProviderType::class)
                            ->default(ProviderType::Brevo)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $type = $state instanceof ProviderType ? $state : ProviderType::tryFrom((string) $state);

                                if ($type?->defaultHost() !== null) {
                                    $set('host', $type->defaultHost());
                                    $set('port', 587);
                                    $set('encryption', SmtpEncryption::StartTls->value);
                                }
                            }),
                        TextInput::make('host')
                            ->label('SMTP host')
                            ->default(ProviderType::Brevo->defaultHost())
                            ->required()
                            ->maxLength(255)
                            ->regex('/^[a-z0-9.-]+$/i')
                            ->validationMessages(['regex' => 'Enter a host name only, without "smtp://" or a port.']),
                        TextInput::make('port')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->default(587)
                            ->required(),
                        Select::make('encryption')
                            ->options(SmtpEncryption::class)
                            ->default(SmtpEncryption::StartTls)
                            ->required(),
                        TextInput::make('username')
                            ->label('SMTP login')
                            ->maxLength(255)
                            ->autocomplete('off'),
                        TextInput::make('password')
                            ->label('SMTP password or key')
                            ->password()
                            ->autocomplete('new-password')
                            ->maxLength(1000)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): string => $operation === 'create'
                                ? 'Stored encrypted. It is never shown again after saving.'
                                : 'Leave empty to keep the saved password. Saved passwords are encrypted and never shown.')
                            ->columnSpanFull(),
                        TextInput::make('api_key')
                            ->label('Brevo API key (optional)')
                            ->password()
                            ->autocomplete('new-password')
                            ->maxLength(1000)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->visible(fn (Get $get): bool => static::isBrevo($get('type')))
                            ->helperText('Different from the SMTP key: create one in Brevo under SMTP & API > API keys. Lets ProSend check sender and domain verification with Brevo. Stored encrypted and never shown again.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Limits')
                    ->description('ProSend never sends beyond these limits. Match them to your provider plan.')
                    ->columnSpan(1)
                    ->schema([
                        TextInput::make('daily_limit')
                            ->label('Emails per day')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->placeholder('No limit')
                            ->helperText('Brevo free plans allow 300 per day.'),
                        TextInput::make('monthly_limit')
                            ->label('Emails per month')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->placeholder('No limit'),
                        TextInput::make('max_per_minute')
                            ->label('Emails per minute')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->placeholder('No limit'),
                        Toggle::make('is_enabled')
                            ->label('Enabled')
                            ->default(true)
                            ->helperText('While disabled, queued emails wait instead of sending.'),
                    ]),
            ]);
    }

    private static function isBrevo(mixed $state): bool
    {
        return ($state instanceof ProviderType ? $state : ProviderType::tryFrom((string) $state)) === ProviderType::Brevo;
    }
}
