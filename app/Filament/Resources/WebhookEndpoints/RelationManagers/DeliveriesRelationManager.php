<?php

namespace App\Filament\Resources\WebhookEndpoints\RelationManagers;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Every event sent to the endpoint, with its outcome. Failed deliveries can be sent again.
 */
class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Delivery log';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('15s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime(),
                TextColumn::make('event')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        WebhookDelivery::SUCCEEDED => 'success',
                        WebhookDelivery::FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('attempts')
                    ->numeric(),
                TextColumn::make('last_status_code')
                    ->label('Response')
                    ->placeholder('—'),
                TextColumn::make('last_error')
                    ->label('Problem')
                    ->limit(60)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([WebhookDelivery::PENDING => 'Pending', WebhookDelivery::SUCCEEDED => 'Delivered', WebhookDelivery::FAILED => 'Failed']),
            ])
            ->recordActions([
                Action::make('payload')
                    ->icon(Heroicon::OutlinedCodeBracket)
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('payload')
                            ->hiddenLabel()
                            ->state(fn (WebhookDelivery $record): string => (string) json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                            ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: monospace;']),
                    ]),
                Action::make('redeliver')
                    ->label('Send again')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (WebhookDelivery $record): bool => $record->status === WebhookDelivery::FAILED && $this->getOwnerRecord() instanceof WebhookEndpoint && auth()->user()->can('update', $this->getOwnerRecord()))
                    ->action(function (WebhookDelivery $record): void {
                        $record->forceFill(['status' => WebhookDelivery::PENDING, 'attempts' => 0, 'last_error' => null])->save();
                        DeliverWebhook::dispatch($record);
                        Notification::make()->title('Queued again')->success()->send();
                    }),
            ]);
    }
}
