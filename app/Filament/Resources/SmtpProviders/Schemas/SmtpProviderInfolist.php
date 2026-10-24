<?php

namespace App\Filament\Resources\SmtpProviders\Schemas;

use App\Enums\ProviderType;
use App\Models\SmtpProvider;
use App\Services\Providers\MailProviderManager;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class SmtpProviderInfolist
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
                        TextEntry::make('type')
                            ->badge(),
                        IconEntry::make('is_enabled')
                            ->label('Enabled')
                            ->boolean(),
                        TextEntry::make('host')
                            ->label('SMTP host')
                            ->formatStateUsing(fn (SmtpProvider $record): string => "{$record->host}:{$record->port}"),
                        TextEntry::make('encryption'),
                        TextEntry::make('username')
                            ->label('SMTP login')
                            ->placeholder('—'),
                        TextEntry::make('password_status')
                            ->label('Password')
                            ->state(fn (SmtpProvider $record): string => filled($record->getRawOriginal('password')) ? 'Saved (encrypted)' : 'Not set'),
                        TextEntry::make('api_key_status')
                            ->label('Brevo API key')
                            ->state(fn (SmtpProvider $record): string => filled($record->getRawOriginal('api_key')) ? 'Saved (encrypted)' : 'Not set: sender and domain verification must be confirmed manually')
                            ->visible(fn (SmtpProvider $record): bool => $record->type === ProviderType::Brevo),
                    ]),
                Section::make('Health')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('health_status')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('last_checked_at')
                            ->label('Last checked')
                            ->since()
                            ->placeholder('Never'),
                        TextEntry::make('last_connected_at')
                            ->label('Last successful connection')
                            ->since()
                            ->placeholder('Never'),
                        TextEntry::make('last_error')
                            ->color('danger')
                            ->placeholder('No errors'),
                    ]),
                Section::make('Usage')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('accepted_today')
                            ->label('Accepted today')
                            ->state(fn (SmtpProvider $record): string => Number::format($record->acceptedCountSince(now()->startOfDay()))),
                        TextEntry::make('daily_remaining')
                            ->label('Left today')
                            ->state(fn (SmtpProvider $record): string => $record->daily_limit === null ? 'No limit' : Number::format($record->remainingDailyQuota()).' of '.Number::format($record->daily_limit)),
                        TextEntry::make('monthly_remaining')
                            ->label('Left this month')
                            ->state(fn (SmtpProvider $record): string => $record->monthly_limit === null ? 'No limit' : Number::format($record->remainingMonthlyQuota()).' of '.Number::format($record->monthly_limit)),
                        TextEntry::make('max_per_minute')
                            ->label('Rate limit')
                            ->suffix(' per minute')
                            ->placeholder('No limit'),
                        TextEntry::make('sender_identities_count')
                            ->label('Sender identities')
                            ->state(fn (SmtpProvider $record): int => $record->senderIdentities()->count()),
                    ]),
                Section::make('Delivery events')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('webhook_status')
                            ->label('Webhook')
                            ->state(fn (SmtpProvider $record): string => match (true) {
                                ! app(MailProviderManager::class)->driver($record)->capabilities()->deliveryWebhooks => 'Not supported by this provider type',
                                filled($record->webhook_token_hash) => 'Webhook URL generated',
                                default => 'Not set up',
                            })
                            ->helperText('Without webhooks ProSend only knows the provider accepted an email, not whether it was delivered, bounced, or marked as spam.'),
                    ]),
            ]);
    }
}
