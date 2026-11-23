<?php

namespace App\Filament\Resources\SmtpProviders\Schemas;

use App\Enums\ProviderType;
use App\Enums\SmtpEncryption;
use App\Models\SmtpProvider;
use App\Services\Providers\SmtpHostPolicy;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
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
                Callout::make('Sandbox: email is captured, never delivered')
                    ->description('Mailtrap Email Sandbox keeps every email in a Mailtrap inbox for inspection, and nothing reaches the recipients. Use it only for testing. A provider can never switch between sandbox and live sending later.')
                    ->warning()
                    ->visible(fn (Get $get): bool => static::type($get('type'))?->isSandbox() === true)
                    ->columnSpanFull(),
                Section::make('Connection')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('For example: Brevo production'),
                        Select::make('type')
                            ->options(fn (?SmtpProvider $record): array => static::typeOptions($record))
                            ->default(ProviderType::Brevo->value)
                            ->required()
                            ->live()
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'A provider cannot switch between sandbox and live sending. Create a new provider instead.' : null)
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                $type = static::type($state);

                                if ($type?->defaultHost() !== null) {
                                    $set('host', $type->defaultHost());
                                    $set('port', $type->defaultPort());
                                    $set('encryption', SmtpEncryption::StartTls->value);
                                }

                                if ($type?->defaultUsername() !== null) {
                                    $set('username', $type->defaultUsername());
                                } elseif ($get('username') === ProviderType::Mailtrap->defaultUsername()) {
                                    $set('username', null);
                                }
                            }),
                        TextInput::make('host')
                            ->label('SMTP host')
                            ->default(ProviderType::Brevo->defaultHost())
                            ->required()
                            ->maxLength(255)
                            ->regex('/^[a-z0-9.-]+$/i')
                            ->validationMessages(['regex' => 'Enter a host name only, without "smtp://" or a port.'])
                            ->dehydrateStateUsing(fn (?string $state): string => rtrim(strtolower(trim((string) $state)), '.'))
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $problem = static::type($get('type'))?->hostProblem((string) $value)
                                        ?? app(SmtpHostPolicy::class)->problemWith((string) $value);

                                    if ($problem !== null) {
                                        $fail($problem);
                                    }
                                },
                            ])
                            ->helperText(fn (Get $get): ?string => match (static::type($get('type'))) {
                                ProviderType::Mailtrap => 'live.smtp.mailtrap.io for transactional email. Mailtrap requires marketing email to use its Bulk Stream, bulk.smtp.mailtrap.io.',
                                ProviderType::MailtrapSandbox => 'Shown in Mailtrap under Sandboxes > your sandbox > Integration > SMTP.',
                                default => null,
                            }),
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
                            ->required(fn (Get $get): bool => in_array(static::type($get('type')), [ProviderType::Mailtrap, ProviderType::MailtrapSandbox], true))
                            ->maxLength(255)
                            ->autocomplete('off')
                            ->helperText(fn (Get $get): ?string => match (static::type($get('type'))) {
                                ProviderType::Mailtrap => 'Always "api" for Mailtrap Email Sending.',
                                ProviderType::MailtrapSandbox => 'Each Mailtrap sandbox has its own login and password.',
                                default => null,
                            }),
                        TextInput::make('password')
                            ->label(fn (Get $get): string => match (static::type($get('type'))) {
                                ProviderType::Mailtrap => 'Mailtrap API token',
                                ProviderType::MailtrapSandbox => 'Sandbox SMTP password',
                                default => 'SMTP password or key',
                            })
                            ->password()
                            ->autocomplete('new-password')
                            ->maxLength(1000)
                            ->required(fn (string $operation, Get $get, ?SmtpProvider $record): bool => $operation === 'create' || static::needsPasswordAgain($get, $record))
                            ->validationMessages([
                                'required' => fn (string $operation): string => $operation === 'create'
                                    ? 'Enter the password, key, or token.'
                                    : 'Enter the password again. A saved password is never sent to a different server or over a connection without encryption.',
                            ])
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation, Get $get): string => ($operation === 'create'
                                ? 'Stored encrypted. It is never shown again after saving.'
                                : 'Leave empty to keep the saved one; it is encrypted and never shown. Changing the host, or turning encryption off, requires entering it again.')
                                .(static::type($get('type')) === ProviderType::Mailtrap ? ' Find it in Mailtrap under Sending Setup > API/SMTP (choose the domain and stream, then SMTP), or create one under Settings > API Tokens.' : ''))
                            ->columnSpanFull(),
                        TextInput::make('api_key')
                            ->label('Brevo API key (optional)')
                            ->password()
                            ->autocomplete('new-password')
                            ->maxLength(1000)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->visible(fn (Get $get): bool => static::type($get('type')) === ProviderType::Brevo)
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
                            ->helperText(fn (Get $get): ?string => match (static::type($get('type'))) {
                                ProviderType::Brevo => 'Brevo free plans allow 300 per day.',
                                ProviderType::Mailtrap => 'Mailtrap\'s free plan allows 150 per day and 4,000 per month; paid plans have no daily cap.',
                                ProviderType::MailtrapSandbox => 'Sandbox email counts too. The monthly allowance depends on your Mailtrap plan.',
                                default => null,
                            }),
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
                            ->placeholder('No limit')
                            ->helperText(fn (Get $get): ?string => static::type($get('type')) === ProviderType::MailtrapSandbox
                                ? 'Mailtrap limits each sandbox per 10 seconds, by plan. Stay well below six times that number, because email can arrive in bursts within a minute.'
                                : null),
                        Toggle::make('is_enabled')
                            ->label('Enabled')
                            ->default(true)
                            ->helperText('While disabled, queued emails wait instead of sending.'),
                    ]),
            ]);
    }

    private static function type(mixed $state): ?ProviderType
    {
        return $state instanceof ProviderType ? $state : ProviderType::tryFrom((string) $state);
    }

    /**
     * A new provider can be any type, except a sandbox where sandboxes are turned off. An existing one can
     * only change to a type on the same side of the sandbox/live line.
     *
     * @return array<string, string>
     */
    private static function typeOptions(?SmtpProvider $record): array
    {
        $types = $record !== null
            ? $record->type->compatibleTypes()
            : array_filter(ProviderType::cases(), fn (ProviderType $type): bool => ! $type->isSandbox() || config('prosend.providers.allow_sandbox'));

        return collect($types)->mapWithKeys(fn (ProviderType $type): array => [$type->value => $type->getLabel()])->all();
    }

    /**
     * A saved secret is only reused for the same server, over a connection at least as protected as before.
     */
    private static function needsPasswordAgain(Get $get, ?SmtpProvider $record): bool
    {
        if ($record === null) {
            return false;
        }

        $encryption = $get('encryption');
        $encryption = $encryption instanceof SmtpEncryption ? $encryption : SmtpEncryption::tryFrom((string) $encryption);

        return rtrim(strtolower(trim((string) $get('host'))), '.') !== strtolower($record->host)
            || ($encryption === SmtpEncryption::None && $record->encryption !== SmtpEncryption::None);
    }
}
