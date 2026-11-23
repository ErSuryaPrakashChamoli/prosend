<?php

namespace App\Filament\Resources\SenderIdentities;

use App\Actions\Deliverability\CheckSenderWithProvider;
use App\Enums\ProviderType;
use App\Filament\Resources\SenderIdentities\Pages\ManageSenderIdentities;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Services\AuditLogger;
use App\Services\Deliverability\DeliverabilityScore;
use App\Services\Providers\Brevo\BrevoApiException;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class SenderIdentityResource extends Resource
{
    protected static ?string $model = SenderIdentity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Deliverability';

    protected static ?string $navigationLabel = 'Sender identities';

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('smtp_provider_id')
                    ->label('Provider')
                    ->relationship('smtpProvider', 'name')
                    ->getOptionLabelFromRecordUsing(fn (SmtpProvider $provider): string => $provider->name.($provider->isSandbox() ? ' (sandbox: captured, not delivered)' : ''))
                    ->preload()
                    ->required()
                    ->rules([
                        fn (?SenderIdentity $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            $problem = $record?->providerChangeProblem(SmtpProvider::query()->find($value));

                            if ($problem !== null) {
                                $fail($problem);
                            }
                        },
                    ])
                    ->helperText('All email from this address goes through this provider, including email already queued. Changing it removes the verification and is recorded in the audit log.'),
                TextInput::make('name')
                    ->label('From name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('For example: Acme Support'),
                TextInput::make('email')
                    ->label('From address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->scopedUnique(ignoreRecord: true)
                    ->helperText('Changing the address removes its verification.'),
                TextInput::make('reply_to')
                    ->label('Default Reply-To')
                    ->email()
                    ->maxLength(255)
                    ->placeholder('Replies go to the From address'),
            ]);
    }

    public static function table(Table $table): Table
    {
        // The health badge and its tooltip share one score per row.
        $scores = [];
        $health = function (SenderIdentity $record) use (&$scores): array {
            return $scores[$record->getKey()] ??= app(DeliverabilityScore::class)->for($record);
        };

        return $table
            ->recordTitleAttribute('email')
            ->modifyQueryUsing(fn ($query) => $query->with(['smtpProvider', 'verifier']))
            ->defaultSort('email')
            ->columns([
                TextColumn::make('email')
                    ->label('From')
                    ->description(fn (SenderIdentity $record): string => $record->name)
                    ->searchable(['email', 'name']),
                TextColumn::make('smtpProvider.name')
                    ->label('Provider')
                    ->description(fn (SenderIdentity $record): ?string => $record->smtpProvider->isSandbox() ? 'Sandbox: captured, not delivered' : null),
                TextColumn::make('verification')
                    ->state(fn (SenderIdentity $record): string => $record->isVerified() ? 'Verified' : 'Not verified')
                    ->badge()
                    ->color(fn (SenderIdentity $record): string => $record->isVerified() ? 'success' : 'warning')
                    ->description(fn (SenderIdentity $record): ?string => $record->isVerified() ? 'by '.($record->verifier?->name ?? 'unknown').' '.$record->verified_at->diffForHumans() : null),
                TextColumn::make('health')
                    ->label('Health')
                    ->badge()
                    ->state(fn (SenderIdentity $record): string => ($score = $health($record))['score'].' · '.$score['grade'])
                    ->color(fn (string $state): string => match (true) {
                        str_ends_with($state, 'Good') => 'success',
                        str_ends_with($state, 'Needs attention') => 'warning',
                        default => 'danger',
                    })
                    ->tooltip(fn (SenderIdentity $record): string => implode(' ', $health($record)['reasons']) ?: 'Nothing to improve that ProSend can measure.'),
                TextColumn::make('reply_to')
                    ->label('Reply-To')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('verified_at')
                    ->label('Verified')
                    ->nullable(),
            ])
            ->recordActions([
                Action::make('checkWithProvider')
                    ->label('Check with Brevo')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->visible(fn (SenderIdentity $record): bool => CheckSenderWithProvider::isAvailable($record) && static::canEdit($record))
                    ->action(function (SenderIdentity $record, CheckSenderWithProvider $check): void {
                        try {
                            $result = $check->handle($record, auth()->user());
                        } catch (BrevoApiException $exception) {
                            Notification::make()->danger()->title('Could not check with Brevo')->body($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->title($result)
                            ->status($record->refresh()->isVerified() ? 'success' : 'warning')
                            ->send();
                    }),
                Action::make('markVerified')
                    ->label('Mark as verified')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (SenderIdentity $record): bool => ! $record->isVerified() && ! CheckSenderWithProvider::isAvailable($record) && static::canEdit($record))
                    ->requiresConfirmation()
                    ->modalHeading('Confirm sender verification')
                    ->modalDescription(fn (SenderIdentity $record): string => "Only confirm after {$record->email} (or its domain) shows as verified in your provider. ".match ($record->smtpProvider->type) {
                        ProviderType::Brevo => 'In Brevo: Senders, Domains & Dedicated IPs.',
                        ProviderType::Mailtrap => 'In Mailtrap: Sending Domains, where the domain must show as verified and compliant.',
                        ProviderType::MailtrapSandbox => 'A sandbox accepts any From address, so this only matters where sender verification is required.',
                        ProviderType::Smtp => 'Check your provider\'s sender or domain settings.',
                        ProviderType::Gmail, ProviderType::Microsoft365 => 'Connected mailboxes send only as their own address, which signing in proved.',
                    }.' ProSend cannot check this automatically for this provider, and unverified senders are rejected or land in spam.')
                    ->action(function (SenderIdentity $record, AuditLogger $auditLogger): void {
                        $record->markVerified(auth()->user());
                        $auditLogger->record('sender.verified', $record, ['email' => $record->email]);

                        Notification::make()->success()->title('Sender marked as verified')->send();
                    }),
                ActionGroup::make([
                    Action::make('revokeVerification')
                        ->label('Remove verification')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->visible(fn (SenderIdentity $record): bool => $record->isVerified() && static::canEdit($record))
                        ->requiresConfirmation()
                        ->action(function (SenderIdentity $record, AuditLogger $auditLogger): void {
                            $record->revokeVerification();
                            $auditLogger->record('sender.verification_removed', $record, ['email' => $record->email]);
                        }),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('No sender identities')
            ->emptyStateDescription('Add the From address you verified with your provider.')
            ->emptyStateIcon(Heroicon::OutlinedIdentification);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSenderIdentities::route('/'),
        ];
    }
}
