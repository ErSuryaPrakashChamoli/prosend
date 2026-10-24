<?php

namespace App\Filament\Resources\Campaigns\RelationManagers;

use App\Actions\Messages\RetryEmailMessage;
use App\Enums\MessageStatus;
use App\Filament\Exports\EmailMessageExporter;
use App\Filament\Resources\EmailMessages\EmailMessageResource;
use App\Models\Campaign;
use App\Models\EmailMessage;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Recipients';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Campaign $campaign */
        $campaign = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('to_email')
            ->defaultSort('id')
            ->poll($campaign->status->isFinished() ? null : '10s')
            ->columns([
                TextColumn::make('to_email')
                    ->label('Recipient')
                    ->description(fn (EmailMessage $record): ?string => $record->to_name)
                    ->searchable(['to_email', 'to_name']),
                TextColumn::make('status')
                    ->badge()
                    ->tooltip(fn (EmailMessage $record): string => $record->status->getDescription()),
                TextColumn::make('attempts')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('accepted_at')
                    ->label('Accepted')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('delivered_at')
                    ->label('Delivered')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(60)
                    ->tooltip(fn (EmailMessage $record): ?string => $record->error_message)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(MessageStatus::class)
                    ->multiple(),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Export results')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->exporter(EmailMessageExporter::class),
            ])
            ->recordActions([
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->visible(fn (EmailMessage $record): bool => RetryEmailMessage::canRetry($record) && auth()->user()->can('retry', $record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (EmailMessage $record): string => $record->status === MessageStatus::Unknown
                        ? 'The provider may already have accepted this email. Retrying could send it twice.'
                        : 'The email is queued for another attempt.')
                    ->action(function (EmailMessage $record, RetryEmailMessage $retry): void {
                        $retry->handle($record);

                        Notification::make()->success()->title('Email queued again')->send();
                    }),
                ViewAction::make()
                    ->url(fn (EmailMessage $record): string => EmailMessageResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
