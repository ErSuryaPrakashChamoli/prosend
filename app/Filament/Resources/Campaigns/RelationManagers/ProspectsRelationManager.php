<?php

namespace App\Filament\Resources\Campaigns\RelationManagers;

use App\Enums\SequenceStatus;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Everyone in the campaign's sequence: where they are, when their next follow-up is due, and why it ended.
 */
class ProspectsRelationManager extends RelationManager
{
    protected static string $relationship = 'prospects';

    protected static ?string $title = 'Prospects';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Campaign
            && auth()->user()->can('view', $ownerRecord)
            && $ownerRecord->prospects()->exists();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Campaign $campaign */
        $campaign = $this->getOwnerRecord();
        $totalSteps = $campaign->totalSteps();
        $canOperate = fn (): bool => auth()->user()->can('operate', $this->getOwnerRecord());

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('contact'))
            ->defaultSort('id')
            ->poll($campaign->status->isFinished() ? null : '30s')
            ->columns([
                TextColumn::make('contact.email')
                    ->label('Prospect')
                    ->description(fn (CampaignProspect $record): ?string => $record->contact->full_name ?: null)
                    ->searchable(['email', 'first_name', 'last_name']),
                TextColumn::make('status')
                    ->badge()
                    ->tooltip(fn (CampaignProspect $record): ?string => $record->finish_reason),
                TextColumn::make('current_step')
                    ->label('Step')
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Not sent yet' : "{$state} of {$totalSteps}"),
                TextColumn::make('next_step_due_at')
                    ->label('Next follow-up')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_sent_at')
                    ->label('Last sent')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('finish_reason')
                    ->label('Ended because')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SequenceStatus::class)
                    ->multiple(),
            ])
            ->recordActions([
                Action::make('history')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->modalHeading(fn (CampaignProspect $record): string => "Sequence history: {$record->contact->email}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        RepeatableEntry::make('events')
                            ->hiddenLabel()
                            ->state(fn (CampaignProspect $record): array => $record->events()->orderBy('id')->get()
                                ->map(fn ($event): array => ['when' => $event->created_at?->toDayDateTimeString(), 'what' => $event->label(), 'details' => $event->details])
                                ->all())
                            ->table([
                                TableColumn::make('When'),
                                TableColumn::make('What'),
                                TableColumn::make('Details'),
                            ])
                            ->schema([
                                TextEntry::make('when'),
                                TextEntry::make('what'),
                                TextEntry::make('details')->placeholder('—'),
                            ]),
                    ]),
                Action::make('markReplied')
                    ->label('Mark as replied')
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->visible(fn (CampaignProspect $record): bool => $record->isActive() && $canOperate())
                    ->requiresConfirmation()
                    ->modalDescription('Records the reply on the prospect, which ends their sequences in every campaign.')
                    ->action(function (CampaignProspect $record): void {
                        $record->contact->update(['last_replied_at' => now()]);
                        Notification::make()->title('Marked as replied')->success()->send();
                    }),
                Action::make('stop')
                    ->label('Stop sequence')
                    ->icon(Heroicon::OutlinedStopCircle)
                    ->color('danger')
                    ->visible(fn (CampaignProspect $record): bool => $record->isActive() && $canOperate())
                    ->requiresConfirmation()
                    ->action(fn (CampaignProspect $record) => $this->stop(collect([$record]))),
            ])
            ->toolbarActions([
                BulkAction::make('stopSelected')
                    ->label('Stop sequences')
                    ->icon(Heroicon::OutlinedStopCircle)
                    ->color('danger')
                    ->visible($canOperate)
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $this->stop($records)),
            ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CampaignProspect>  $prospects
     */
    private function stop(\Illuminate\Support\Collection $prospects): void
    {
        $user = auth()->user();
        $stopped = $prospects
            ->filter(fn (CampaignProspect $prospect): bool => $prospect->campaign_id === $this->getOwnerRecord()->getKey())
            ->filter(fn (CampaignProspect $prospect): bool => $prospect->finish(SequenceStatus::Stopped, "Stopped by {$user->name}"))
            ->count();

        app(AuditLogger::class)->record('campaign.sequences_stopped', $this->getOwnerRecord(), ['prospects' => $stopped]);

        Notification::make()->title("{$stopped} sequence(s) stopped")->success()->send();
    }
}
