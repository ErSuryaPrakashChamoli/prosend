<?php

namespace App\Filament\Resources\ApiKeys;

use App\Enums\ApiKeyMode;
use App\Enums\ApiScope;
use App\Filament\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Models\ApiKey;
use App\Models\SenderIdentity;
use App\Services\AuditLogger;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Component;
use UnitEnum;

class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?string $navigationLabel = 'API keys';

    protected static ?string $modelLabel = 'API key';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('For example: Shop checkout service'),
                Radio::make('mode')
                    ->options(ApiKeyMode::class)
                    ->default(ApiKeyMode::Live)
                    ->required()
                    ->disabledOn('edit'),
                CheckboxList::make('scopes')
                    ->options(ApiScope::class)
                    ->required()
                    ->live()
                    ->columnSpanFull(),
                Select::make('allowed_sender_identity_ids')
                    ->label('May send from')
                    ->options(fn (): array => SenderIdentity::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->multiple()
                    ->required(fn (Get $get): bool => in_array(ApiScope::EmailsSend->value, array_map(fn (mixed $scope): string => $scope instanceof ApiScope ? $scope->value : (string) $scope, (array) $get('scopes')), true))
                    ->helperText('Requests with any other From address are refused.')
                    ->columnSpanFull(),
                TextInput::make('rate_limit_per_minute')
                    ->label('Requests per minute')
                    ->numeric()->integer()->minValue(1)->maxValue(1000)
                    ->default(60)
                    ->required(),
                TextInput::make('daily_send_limit')
                    ->label('Emails per day')
                    ->numeric()->integer()->minValue(1)
                    ->placeholder('No limit beyond the provider\'s'),
                TagsInput::make('allowed_ips')
                    ->label('Allowed IP addresses')
                    ->placeholder('203.0.113.7 or 203.0.113.0/24')
                    ->helperText('Leave empty to allow any address.')
                    ->nestedRecursiveRules([fn (): Closure => static::ipOrCidrRule()]),
                DateTimePicker::make('expires_at')
                    ->label('Expires')
                    ->seconds(false)
                    ->minDate(now()->startOfMinute())
                    ->placeholder('Never'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('masked')->label('Key')->state(fn (ApiKey $record): string => $record->maskedKey())->fontFamily('mono'),
                TextEntry::make('mode')->badge(),
                TextEntry::make('scopes')->badge()->color('gray'),
                TextEntry::make('allowed_senders')
                    ->label('May send from')
                    ->state(fn (ApiKey $record): array => SenderIdentity::query()->whereKey($record->allowed_sender_identity_ids ?? [])->pluck('email')->all())
                    ->placeholder('No senders'),
                TextEntry::make('rate_limit_per_minute')->label('Requests per minute'),
                TextEntry::make('daily_send_limit')->label('Emails per day')->placeholder('No limit'),
                TextEntry::make('allowed_ips')->label('Allowed IP addresses')->badge()->color('gray')->placeholder('Any'),
                TextEntry::make('last_used_at')->label('Last used')->since()->placeholder('Never')->helperText(fn (ApiKey $record): ?string => $record->last_used_ip),
                TextEntry::make('creator.name')->label('Created by')->placeholder('—'),
                TextEntry::make('rotatedFrom.name')->label('Replaces')->placeholder('—'),
                Section::make('Last 14 days')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('usage')
                            ->hiddenLabel()
                            ->state(fn (ApiKey $record): array => $record->dailyUsage()
                                ->where('date', '>=', now()->subDays(13)->toDateString())
                                ->orderByDesc('date')
                                ->get()
                                ->map(fn ($usage): array => [
                                    'date' => $usage->date->toFormattedDateString(),
                                    'requests' => $usage->requests,
                                    'emails_queued' => $usage->emails_queued,
                                    'errors' => $usage->client_errors + $usage->server_errors,
                                ])
                                ->all())
                            ->table([
                                TableColumn::make('Day'),
                                TableColumn::make('Requests'),
                                TableColumn::make('Emails queued'),
                                TableColumn::make('Errors'),
                            ])
                            ->schema([
                                TextEntry::make('date'),
                                TextEntry::make('requests'),
                                TextEntry::make('emails_queued'),
                                TextEntry::make('errors'),
                            ])
                            ->placeholder('No requests yet'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (ApiKey $record): string => $record->maskedKey())
                    ->searchable(),
                TextColumn::make('mode')
                    ->badge(),
                TextColumn::make('status')
                    ->state(fn (ApiKey $record): string => match (true) {
                        $record->isRevoked() => 'Revoked',
                        $record->isExpired() => 'Expired',
                        $record->expires_at !== null => 'Expires '.$record->expires_at->diffForHumans(),
                        default => 'Active',
                    })
                    ->badge()
                    ->color(fn (ApiKey $record): string => $record->isActive() ? 'success' : 'gray'),
                TextColumn::make('scopes')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make()
                        ->after(fn (ApiKey $record, AuditLogger $auditLogger) => $auditLogger->record('api_key.updated', $record, static::auditDetails($record))),
                    static::rotateAction(),
                    static::revokeAction(),
                ]),
            ])
            ->emptyStateHeading('No API keys')
            ->emptyStateDescription('Create a key for each application that sends email through ProSend.')
            ->emptyStateIcon(Heroicon::OutlinedKey);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageApiKeys::route('/'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function auditDetails(ApiKey $apiKey): array
    {
        return [
            'name' => $apiKey->name,
            'key' => $apiKey->maskedKey(),
            'mode' => $apiKey->mode->value,
            'scopes' => $apiKey->scopes,
        ];
    }

    private static function rotateAction(): Action
    {
        return Action::make('rotate')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (ApiKey $record): bool => $record->isActive() && static::canEdit($record))
            ->modalDescription('A new key is created with the same settings. Update your application before the old key stops working.')
            ->schema([
                Select::make('grace_days')
                    ->label('Keep the old key working for')
                    ->options([0 => 'No time: stop it now', 1 => '1 day', 3 => '3 days', 7 => '7 days'])
                    ->default(1)
                    ->required(),
            ])
            ->action(function (ApiKey $record, array $data, Component $livewire, AuditLogger $auditLogger): void {
                [$successor, $plaintext] = $record->rotate((int) $data['grace_days'], auth()->user());

                $auditLogger->record('api_key.rotated', $successor, [...static::auditDetails($successor), 'replaces' => $record->maskedKey(), 'grace_days' => (int) $data['grace_days']]);

                $livewire->replaceMountedAction('showNewKey', ['key' => $plaintext]);
            });
    }

    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (ApiKey $record): bool => ! $record->isRevoked() && static::canEdit($record))
            ->requiresConfirmation()
            ->modalDescription('Requests with this key are refused from the next request on. This cannot be undone.')
            ->action(function (ApiKey $record, AuditLogger $auditLogger): void {
                $record->revoke(auth()->user());
                $auditLogger->record('api_key.revoked', $record, static::auditDetails($record));

                Notification::make()->success()->title('API key revoked')->send();
            });
    }

    /**
     * Accepts an IPv4 or IPv6 address, optionally with a CIDR prefix length.
     */
    private static function ipOrCidrRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            [$address, $prefix] = array_pad(explode('/', (string) $value, 2), 2, null);
            $isV6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $isIp = filter_var($address, FILTER_VALIDATE_IP) !== false;
            $prefixValid = $prefix === null || (ctype_digit($prefix) && (int) $prefix <= ($isV6 ? 128 : 32));

            if (! $isIp || ! $prefixValid) {
                $fail("\"{$value}\" is not an IP address or range.");
            }
        };
    }
}
