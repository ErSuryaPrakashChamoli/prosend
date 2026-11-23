<?php

namespace App\Filament\Resources\Conversations;

use App\Enums\ConversationStatus;
use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Pages\ViewConversation;
use App\Models\Conversation;
use App\Services\Inbox\ConversationManager;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * The unified inbox: every prospect reply from every inbound mailbox of the client, as conversations.
 */
class ConversationResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?string $navigationLabel = 'Inbox';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'subject';

    /**
     * People without "see every conversation" only reach their own.
     *
     * @return Builder<Conversation>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        $unread = static::getEloquentQuery()->where('is_read', false)->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Unread conversations';
    }

    public static function table(Table $table): Table
    {
        $unread = fn (Conversation $record): ?FontWeight => $record->is_read ? null : FontWeight::Bold;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contact', 'campaign', 'assignee']))
            ->defaultSort('last_message_at', 'desc')
            ->poll('60s')
            ->recordUrl(fn (Conversation $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                IconColumn::make('is_starred')
                    ->label('')
                    ->icon(fn (bool $state): Heroicon => $state ? Heroicon::Star : Heroicon::OutlinedStar)
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('contact.email')
                    ->label('Prospect')
                    ->description(fn (Conversation $record): ?string => $record->contact?->full_name ?: null)
                    ->placeholder('Unknown sender')
                    ->weight($unread),
                TextColumn::make('subject')
                    ->description(fn (Conversation $record): ?string => $record->campaign?->name)
                    ->weight($unread)
                    ->limit(70)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $matches) => $matches
                        ->whereContainsText('subject', $search)
                        ->orWhereHas('contact', fn (Builder $contacts) => $contacts->whereContains('email', $search)->orWhere(fn (Builder $names) => $names->whereContains('first_name', $search))->orWhere(fn (Builder $names) => $names->whereContains('last_name', $search)))
                        ->orWhereHas('messages', fn (Builder $messages) => $messages->whereContainsText('text_body', $search)))),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('assignee.name')
                    ->label('Assigned to')
                    ->placeholder('Unassigned')
                    ->toggleable(),
                TextColumn::make('last_message_at')
                    ->label('Last message')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ConversationStatus::class)
                    ->multiple(),
                TernaryFilter::make('is_read')
                    ->label('Read'),
                TernaryFilter::make('is_starred')
                    ->label('Starred'),
                SelectFilter::make('assigned_to')
                    ->label('Assigned to')
                    ->relationship('assignee', 'name', fn (Builder $query) => $query->memberOf()),
                SelectFilter::make('campaign')
                    ->relationship('campaign', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('markRead')
                    ->label('Mark as read')
                    ->icon(Heroicon::OutlinedEnvelopeOpen)
                    ->action(fn (Collection $records) => static::updateEach($records, ['is_read' => true])),
                BulkAction::make('markUnread')
                    ->label('Mark as unread')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->action(fn (Collection $records) => static::updateEach($records, ['is_read' => false])),
                BulkAction::make('setStatus')
                    ->label('Set status')
                    ->icon(Heroicon::OutlinedTag)
                    ->schema([
                        Select::make('status')->options(ConversationStatus::class)->required(),
                    ])
                    ->action(fn (Collection $records, array $data) => static::updateEach($records, ['status' => $data['status']])),
            ])
            ->emptyStateHeading('No replies yet')
            ->emptyStateDescription('Replies to your campaigns appear here once an inbound mailbox is set up under Infrastructure > Inbound mailboxes.')
            ->emptyStateIcon(Heroicon::OutlinedInbox);
    }

    /**
     * Apply the change to each conversation the person may update; the others are left as they are.
     *
     * @param  Collection<int, Conversation>  $records
     * @param  array<string, mixed>  $attributes
     */
    private static function updateEach(Collection $records, array $attributes): void
    {
        $records
            ->filter(fn (Conversation $conversation): bool => auth()->user()->can('update', $conversation))
            ->each(fn (Conversation $conversation) => app(ConversationManager::class)->update($conversation, $attributes));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
            'view' => ViewConversation::route('/{record}'),
        ];
    }
}
