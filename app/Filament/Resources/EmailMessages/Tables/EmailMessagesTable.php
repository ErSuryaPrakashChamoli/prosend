<?php

namespace App\Filament\Resources\EmailMessages\Tables;

use App\Actions\Messages\RetryEmailMessage;
use App\Enums\EmailPurpose;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Models\EmailMessage;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmailMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['campaign', 'smtpProvider', 'apiKey']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('to_email')
                    ->label('Recipient')
                    ->description(fn (EmailMessage $record): ?string => $record->to_name)
                    ->searchable(['to_email', 'to_name']),
                TextColumn::make('subject')
                    ->limit(50)
                    ->tooltip(fn (EmailMessage $record): string => $record->subject)
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->tooltip(fn (EmailMessage $record): string => $record->status->getDescription()),
                TextColumn::make('purpose')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->placeholder('Individual email')
                    ->toggleable(),
                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->description(fn (EmailMessage $record): ?string => $record->apiKey?->name)
                    ->toggleable(),
                TextColumn::make('smtpProvider.name')
                    ->label('Provider')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attempts')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(50)
                    ->tooltip(fn (EmailMessage $record): ?string => $record->error_message)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(MessageStatus::class)
                    ->multiple(),
                SelectFilter::make('purpose')
                    ->options(EmailPurpose::class),
                SelectFilter::make('source')
                    ->options(MessageSource::class),
                SelectFilter::make('apiKey')
                    ->label('API key')
                    ->relationship('apiKey', 'name'),
                SelectFilter::make('campaign')
                    ->relationship('campaign', 'name')
                    ->searchable(),
                SelectFilter::make('smtpProvider')
                    ->label('Provider')
                    ->relationship('smtpProvider', 'name'),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))),
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
                ViewAction::make(),
            ])
            ->emptyStateHeading('No email activity yet')
            ->emptyStateDescription('Emails you compose or send in campaigns appear here with their delivery status.')
            ->emptyStateIcon(Heroicon::OutlinedInboxStack);
    }
}
