<?php

namespace App\Filament\Resources\Conversations\Pages;

use App\Enums\ConversationStatus;
use App\Filament\Resources\Conversations\ConversationResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListConversations extends ListRecords
{
    protected static string $resource = ConversationResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'unread' => Tab::make('Unread')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_read', false)),
            'mine' => Tab::make('Assigned to me')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('assigned_to', auth()->id())),
            'interested' => Tab::make('Interested')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ConversationStatus::positive())),
            'follow_up' => Tab::make('Follow-up')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ConversationStatus::FollowUp)),
            'starred' => Tab::make('Starred')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_starred', true)),
        ];
    }
}
