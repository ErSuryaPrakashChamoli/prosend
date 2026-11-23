<?php

namespace App\Filament\Resources\InboundMailboxes;

use App\Enums\ProviderHealth;
use App\Enums\SmtpEncryption;
use App\Filament\Resources\InboundMailboxes\Pages\ManageInboundMailboxes;
use App\Jobs\SyncInboundMailbox;
use App\Models\InboundMailbox;
use App\Services\Inbox\Contracts\MailboxReader;
use App\Services\Providers\SmtpHostPolicy;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * Mailboxes ProSend reads over IMAP for replies and bounce reports: usually the inbox of each sending address, or the
 * reply-to address campaigns use.
 */
class InboundMailboxResource extends Resource
{
    protected static ?string $model = InboundMailbox::class;

    protected static ?string $modelLabel = 'inbound mailbox';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?string $navigationLabel = 'Inbound mailboxes';

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->scopedUnique(ignoreRecord: true)
                    ->helperText('The address replies arrive at.'),
                TextInput::make('host')
                    // A Gmail or Microsoft 365 mailbox is read through its OAuth connection instead.
                    ->hidden(fn (?InboundMailbox $record): bool => $record !== null && ! $record->isImap())
                    ->label('IMAP host')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('imap.example.com')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $problem = app(SmtpHostPolicy::class)->problemWith((string) $value);

                        if ($problem !== null) {
                            $fail($problem);
                        }
                    }),
                Select::make('encryption')
                    // A Gmail or Microsoft 365 mailbox is read through its OAuth connection instead.
                    ->hidden(fn (?InboundMailbox $record): bool => $record !== null && ! $record->isImap())
                    ->options(SmtpEncryption::class)
                    ->default(SmtpEncryption::Tls)
                    ->required()
                    ->helperText('TLS uses port 993, STARTTLS port 143.'),
                TextInput::make('port')
                    // A Gmail or Microsoft 365 mailbox is read through its OAuth connection instead.
                    ->hidden(fn (?InboundMailbox $record): bool => $record !== null && ! $record->isImap())
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->placeholder('993'),
                TextInput::make('folder')
                    // A Gmail or Microsoft 365 mailbox is read through its OAuth connection instead.
                    ->hidden(fn (?InboundMailbox $record): bool => $record !== null && ! $record->isImap())
                    ->default('INBOX')
                    ->required()
                    ->maxLength(255),
                TextInput::make('username')
                    // A Gmail or Microsoft 365 mailbox is read through its OAuth connection instead.
                    ->hidden(fn (?InboundMailbox $record): bool => $record !== null && ! $record->isImap())
                    ->maxLength(255)
                    ->helperText('Usually the address itself.'),
                TextInput::make('password')
                    // A Gmail or Microsoft 365 mailbox is read through its OAuth connection instead.
                    ->hidden(fn (?InboundMailbox $record): bool => $record !== null && ! $record->isImap())
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    // A new host, or turning encryption off, needs the password again: the saved one is never sent
                    // anywhere it was not entered for.
                    ->required(fn (string $operation, Get $get, ?InboundMailbox $record): bool => $operation === 'create'
                        || ($record !== null && (static::normalizeHost((string) $get('host')) !== static::normalizeHost((string) $record->host)
                            || ($get('encryption') instanceof SmtpEncryption ? $get('encryption') : SmtpEncryption::tryFrom((string) $get('encryption'))) === SmtpEncryption::None)))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (string $operation): string => $operation === 'edit'
                        ? 'Stored encrypted. Leave empty to keep it, unless you change the host or turn encryption off.'
                        : 'Stored encrypted. For Gmail or Microsoft 365, use an app password.'),
                Toggle::make('is_enabled')
                    ->label('Read this mailbox')
                    ->default(true)
                    ->helperText('Checked every 5 minutes. Email is never marked as read or deleted.'),
            ]);
    }

    public static function normalizeHost(string $host): string
    {
        return rtrim(Str::lower(trim($host)), '.');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('email')
            ->columns([
                TextColumn::make('email')
                    ->label('Address')
                    ->description(fn (InboundMailbox $record): string => $record->name)
                    ->searchable(['email', 'name']),
                TextColumn::make('host')
                    ->label('Connection')
                    ->state(fn (InboundMailbox $record): string => match ($record->type) {
                        InboundMailbox::TYPE_GMAIL => 'Gmail API (OAuth)',
                        InboundMailbox::TYPE_MICROSOFT => 'Microsoft Graph (OAuth)',
                        default => "IMAP · {$record->host}",
                    })
                    ->description(fn (InboundMailbox $record): ?string => $record->isImap() ? $record->folder : null),
                IconColumn::make('is_enabled')
                    ->label('Read')
                    ->boolean(),
                TextColumn::make('health_status')
                    ->label('Status')
                    ->badge()
                    ->tooltip(fn (InboundMailbox $record): ?string => $record->last_error),
                TextColumn::make('last_synced_at')
                    ->label('Last checked')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->recordActions([
                Action::make('test')
                    ->label('Test connection')
                    ->icon(Heroicon::OutlinedSignal)
                    ->visible(fn (InboundMailbox $record): bool => static::canEdit($record))
                    ->action(function (InboundMailbox $record, MailboxReader $reader): void {
                        try {
                            $reader->testConnection($record);
                            $record->forceFill(['health_status' => ProviderHealth::Healthy, 'last_error' => null])->save();
                            Notification::make()->title('Connected')->body("Signed in to {$record->email} and opened {$record->folder}.")->success()->send();
                        } catch (Throwable $exception) {
                            $record->recordSyncFailure(class_basename($exception).': '.Str::limit($exception->getMessage(), 300));
                            Notification::make()->title('Could not connect')->body(Str::limit($exception->getMessage(), 300))->danger()->send();
                        }
                    }),
                Action::make('sync')
                    ->label('Check now')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (InboundMailbox $record): bool => $record->is_enabled && static::canEdit($record))
                    ->action(function (InboundMailbox $record): void {
                        SyncInboundMailbox::dispatch($record);
                        Notification::make()->title('Check queued')->body('New replies appear in the inbox once the queue worker has read the mailbox.')->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No inbound mailboxes')
            ->emptyStateDescription('Add the mailbox replies arrive at, so replies stop sequences and appear in the inbox, and bounce reports suppress dead addresses.')
            ->emptyStateIcon(Heroicon::OutlinedInboxArrowDown);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageInboundMailboxes::route('/'),
        ];
    }
}
