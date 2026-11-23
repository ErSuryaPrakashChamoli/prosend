<?php

namespace App\Filament\Resources\Campaigns\Tables;

use App\Enums\CampaignStatus;
use App\Enums\EmailPurpose;
use App\Enums\MessageStatus;
use App\Enums\SequenceStatus;
use App\Models\Campaign;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('senderIdentity')
                ->withCount([
                    'messages',
                    'prospects',
                    'prospects as replied_count' => fn (Builder $prospects) => $prospects->where('status', SequenceStatus::Replied),
                    'steps',
                    'messages as accepted_count' => fn (Builder $messages) => $messages->whereIn('status', MessageStatus::acceptedByProvider()),
                    'messages as delivered_count' => fn (Builder $messages) => $messages->where('status', MessageStatus::Delivered),
                    'messages as problem_count' => fn (Builder $messages) => $messages->whereIn('status', [MessageStatus::Failed, MessageStatus::Bounced, MessageStatus::Complained, MessageStatus::Unknown]),
                ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Campaign $record): ?string => $record->subject)
                    ->searchable(['name', 'subject'])
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('purpose')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('prospects_count')
                    ->label('Recipients')
                    // Campaigns sent before sequences existed have emails but no prospect records.
                    ->state(fn (Campaign $record): int => $record->prospects_count > 0 ? $record->prospects_count : $record->messages_count)
                    ->description(fn (Campaign $record): ?string => $record->steps_count > 0 ? (1 + $record->steps_count).' steps' : null)
                    ->numeric(),
                TextColumn::make('replied_count')
                    ->label('Replied')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('accepted_count')
                    ->label('Accepted')
                    ->numeric()
                    ->tooltip('Accepted by the provider. Not proof of inbox delivery.'),
                TextColumn::make('delivered_count')
                    ->label('Delivered')
                    ->numeric()
                    ->tooltip('Confirmed by provider delivery events.'),
                TextColumn::make('problem_count')
                    ->label('Problems')
                    ->numeric()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->tooltip('Failed, bounced, complained, or unknown outcome.'),
                TextColumn::make('scheduled_at')
                    ->label('Scheduled')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('archived')
                    ->label('Archived')
                    ->placeholder('Hide archived')
                    ->trueLabel('Only archived')
                    ->falseLabel('All campaigns')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->whereNull('archived_at'),
                    ),
                SelectFilter::make('status')
                    ->options(CampaignStatus::class)
                    ->multiple(),
                SelectFilter::make('purpose')
                    ->options(EmailPurpose::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No campaigns yet')
            ->emptyStateDescription('Create a campaign to send an email, with optional follow-ups, to a list, a segment or chosen prospects.')
            ->emptyStateIcon(Heroicon::OutlinedMegaphone);
    }
}
