<?php

namespace App\Filament\Resources\EmailMessages\Schemas;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\EmailMessage;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmailMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Message')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('to_email')
                            ->label('To')
                            ->formatStateUsing(fn (EmailMessage $record): string => $record->to_name ? "{$record->to_name} <{$record->to_email}>" : $record->to_email)
                            ->copyable(),
                        TextEntry::make('senderIdentity.email')
                            ->label('From')
                            ->formatStateUsing(fn (EmailMessage $record): string => "{$record->senderIdentity->name} <{$record->senderIdentity->email}>"),
                        TextEntry::make('cc')
                            ->label('CC')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('bcc')
                            ->label('BCC')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('reply_to')
                            ->label('Reply-To')
                            ->placeholder('Sender default'),
                        TextEntry::make('purpose')
                            ->badge(),
                        TextEntry::make('subject')
                            ->columnSpanFull(),
                        TextEntry::make('campaign.name')
                            ->label('Campaign')
                            ->url(fn (EmailMessage $record): ?string => $record->campaign ? CampaignResource::getUrl('view', ['record' => $record->campaign]) : null)
                            ->placeholder('Individual email'),
                        TextEntry::make('attachments')
                            ->formatStateUsing(fn (array $state): string => $state['name'] ?? '')
                            ->badge()
                            ->color('gray')
                            ->placeholder('None'),
                    ]),
                Section::make('Delivery')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->helperText(fn (EmailMessage $record): string => $record->status->getDescription()),
                        TextEntry::make('smtpProvider.name')
                            ->label('Provider')
                            ->placeholder('—'),
                        TextEntry::make('attempts'),
                        TextEntry::make('scheduled_at')
                            ->label('Scheduled for')
                            ->dateTime()
                            ->placeholder('Sent immediately'),
                        TextEntry::make('accepted_at')
                            ->label('Accepted by provider')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('delivered_at')
                            ->label('Confirmed delivered')
                            ->dateTime()
                            ->placeholder('No delivery event yet'),
                        TextEntry::make('bounce_type')
                            ->badge()
                            ->color('danger')
                            ->placeholder('—'),
                        TextEntry::make('error_message')
                            ->label('Last error')
                            ->color('danger')
                            ->prefix(fn (EmailMessage $record): ?string => $record->error_code ? "[{$record->error_code}] " : null)
                            ->placeholder('—'),
                        TextEntry::make('provider_message_id')
                            ->label('Message-ID')
                            ->copyable()
                            ->size('xs')
                            ->placeholder('Assigned when sent'),
                    ]),
                Section::make('Events')
                    ->description('Opens and clicks can be triggered by privacy proxies and security scanners, so treat them as approximate.')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('events')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('When'),
                                TableColumn::make('Event'),
                                TableColumn::make('Source'),
                                TableColumn::make('Recipient'),
                                TableColumn::make('Details'),
                            ])
                            ->schema([
                                TextEntry::make('occurred_at')->dateTime(),
                                TextEntry::make('type')->badge(),
                                TextEntry::make('source'),
                                TextEntry::make('recipient_email')->placeholder('—'),
                                KeyValueEntry::make('details')->placeholder('—'),
                            ])
                            ->placeholder('No events yet'),
                    ]),
            ]);
    }
}
