<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Enums\AudienceType;
use App\Enums\ConsentStatus;
use App\Enums\ContactStage;
use App\Enums\EmailPurpose;
use App\Enums\OutreachEvent;
use App\Enums\Permission;
use App\Enums\SequenceStatus;
use App\Filament\Pages\ComposeEmail;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Segment;
use App\Models\User;
use App\Services\Automation\OutreachEvents;
use App\Services\Campaigns\SequenceProgress;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Number;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lists', 'suppression', 'owner']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')
                    ->label('Email address')
                    ->description(fn (Contact $record): ?string => $record->full_name)
                    ->searchable(['email', 'first_name', 'last_name'])
                    ->sortable()
                    ->copyable(),
                TextColumn::make('company')
                    ->description(fn (Contact $record): ?string => $record->job_title)
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('industry')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stage')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('lists.name')
                    ->label('Lists')
                    ->badge()
                    ->limitList(3)
                    ->toggleable(),
                TextColumn::make('consent_status')
                    ->label('Consent')
                    ->badge()
                    ->sortable(),
                TextColumn::make('suppression.reason')
                    ->label('Suppression')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('last_contacted_at')
                    ->label('Last contacted')
                    ->since()
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('unsubscribed_at')
                    ->label('Unsubscribed')
                    ->since()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('tags')
                    ->badge()
                    ->color('gray')
                    ->limitList(3)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('lists')
                    ->relationship('lists', 'name')
                    ->multiple()
                    ->preload(),
                SelectFilter::make('segment')
                    ->options(fn (): array => Segment::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => ($segment = Segment::query()->find($data['value'] ?? null))
                        ? $query->whereIn('contacts.id', $segment->contactsQuery()->select('contacts.id'))
                        : $query),
                SelectFilter::make('stage')
                    ->options(ContactStage::class)
                    ->multiple(),
                SelectFilter::make('owner')
                    ->relationship('owner', 'name', fn (Builder $query) => $query->memberOf())
                    ->preload(),
                SelectFilter::make('industry')
                    ->options(fn (): array => Contact::query()
                        ->whereNotNull('industry')
                        ->where('industry', '!=', '')
                        ->distinct()
                        ->orderBy('industry')
                        ->limit(500)
                        ->pluck('industry', 'industry')
                        ->all())
                    ->multiple()
                    ->searchable(),
                Filter::make('details')
                    ->schema([
                        TextInput::make('company')->label('Company contains'),
                        TextInput::make('job_title')->label('Job title contains'),
                        TextInput::make('source')->label('Source contains'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        foreach (['company', 'job_title', 'source'] as $column) {
                            if (filled($data[$column] ?? null)) {
                                $query->whereContains($column, trim($data[$column]));
                            }
                        }

                        return $query;
                    })
                    ->indicateUsing(fn (array $data): array => collect(['company' => 'Company', 'job_title' => 'Job title', 'source' => 'Source'])
                        ->filter(fn (string $label, string $column): bool => filled($data[$column] ?? null))
                        ->map(fn (string $label, string $column): string => "{$label} contains \"{$data[$column]}\"")
                        ->values()
                        ->all()),
                SelectFilter::make('consent_status')
                    ->label('Consent')
                    ->options(ConsentStatus::class),
                TernaryFilter::make('unsubscribed')
                    ->nullable()
                    ->attribute('unsubscribed_at')
                    ->trueLabel('Unsubscribed')
                    ->falseLabel('Subscribed'),
                Filter::make('suppressed')
                    ->label('Suppressed')
                    ->query(fn (Builder $query) => $query->whereHas('suppression'))
                    ->toggle(),
                SelectFilter::make('eligible_for')
                    ->label('Eligible for')
                    ->options(EmailPurpose::class)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->eligibleFor(EmailPurpose::from($data['value']))
                        : $query),
            ])
            ->recordActions([
                Action::make('compose')
                    ->label('Email')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->color('gray')
                    ->visible(fn (): bool => ComposeEmail::canAccess())
                    ->url(fn (Contact $record): string => ComposeEmail::getUrl(['to' => $record->email])),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::createCampaignAction(),
                    static::addToListAction(),
                    static::removeFromListAction(),
                    static::setStageAction(),
                    static::assignOwnerAction(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No contacts yet')
            ->emptyStateDescription('Import a CSV file or paste a list of email addresses to get started.')
            ->emptyStateIcon(Heroicon::OutlinedUsers);
    }

    private static function createCampaignAction(): BulkAction
    {
        return BulkAction::make('createCampaign')
            ->label('Create campaign')
            ->icon(Heroicon::OutlinedMegaphone)
            ->visible(fn (): bool => CampaignResource::canCreate())
            ->modalDescription('Creates a draft campaign for the selected contacts. You can write the email and review eligibility before anything is sent.')
            ->schema([
                TextInput::make('name')
                    ->label('Campaign name')
                    ->required()
                    ->maxLength(255),
                Radio::make('purpose')
                    ->options(EmailPurpose::class)
                    ->default(EmailPurpose::Marketing)
                    ->required(),
            ])
            ->action(function (Collection $records, array $data, Action $action): void {
                $campaign = Campaign::query()->create([
                    'name' => $data['name'],
                    'purpose' => $data['purpose'],
                    'audience_type' => AudienceType::SelectedContacts,
                    'send_rate_per_minute' => config('prosend.campaigns.default_send_rate_per_minute'),
                ]);
                $campaign->forceFill(['created_by' => auth()->id()])->save();
                $campaign->selectedContacts()->attach($records->modelKeys());

                $action->redirect(CampaignResource::getUrl('edit', ['record' => $campaign]));
            });
    }

    private static function addToListAction(): BulkAction
    {
        return BulkAction::make('addToList')
            ->label('Add to list')
            ->icon(Heroicon::OutlinedQueueList)
            ->visible(fn (): bool => auth()->user()->hasPermission(Permission::ManageProspects))
            ->schema([
                Select::make('contact_list_id')
                    ->label('List')
                    ->options(fn (): array => ContactList::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->createOptionForm([
                        TextInput::make('name')->required()->maxLength(255)->scopedUnique(ContactList::class, 'name'),
                    ])
                    ->createOptionUsing(fn (array $data): int => ContactList::query()->create($data)->getKey()),
            ])
            ->action(function (Collection $records, array $data): void {
                $list = ContactList::query()->findOrFail($data['contact_list_id']);
                $list->contacts()->syncWithoutDetaching($records->modelKeys());

                Notification::make()
                    ->success()
                    ->title(Number::format($records->count()).' contacts added to '.$list->name)
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function setStageAction(): BulkAction
    {
        return BulkAction::make('setStage')
            ->label('Set stage')
            ->icon(Heroicon::OutlinedFunnel)
            ->visible(fn (): bool => auth()->user()->hasPermission(Permission::ManageProspects))
            ->schema([
                Select::make('stage')
                    ->options(ContactStage::class)
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $stage = $data['stage'] instanceof ContactStage ? $data['stage'] : ContactStage::from($data['stage']);
                Contact::query()->whereKey($records->modelKeys())->update(['stage' => $stage]);

                // A bulk update skips model events: sequences are ended, and rules and webhooks told, here instead.
                $records->filter(fn (Contact $contact): bool => $contact->stage !== $stage)->each(function (Contact $contact) use ($stage): void {
                    if ($stage->meansReplied()) {
                        app(SequenceProgress::class)->stopForContact($contact, SequenceStatus::Replied, 'Replied');
                    }

                    app(OutreachEvents::class)->dispatch(OutreachEvent::ProspectStageChanged, $contact->client_id, $contact->forceFill(['stage' => $stage]), [
                        'previous_stage' => $contact->getOriginal('stage')?->value,
                        'stage' => $stage->value,
                    ], "stage:{$contact->getKey()}:{$stage->value}:".now()->getTimestamp());
                });

                Notification::make()
                    ->success()
                    ->title(Number::format($records->count()).' contacts moved to '.$stage->getLabel())
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function assignOwnerAction(): BulkAction
    {
        return BulkAction::make('assignOwner')
            ->label('Assign owner')
            ->icon(Heroicon::OutlinedUserCircle)
            ->visible(fn (): bool => auth()->user()->hasPermission(Permission::ManageProspects))
            ->schema([
                Select::make('owner_id')
                    ->label('Owner')
                    ->options(fn (): array => User::query()->memberOf()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->placeholder('Unassigned'),
            ])
            ->action(function (Collection $records, array $data): void {
                $owner = filled($data['owner_id'] ?? null) ? User::query()->memberOf()->findOrFail($data['owner_id']) : null;
                Contact::query()->whereKey($records->modelKeys())->update(['owner_id' => $owner?->getKey()]);

                Notification::make()
                    ->success()
                    ->title(Number::format($records->count()).' contacts '.($owner ? "assigned to {$owner->name}" : 'unassigned'))
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function removeFromListAction(): BulkAction
    {
        return BulkAction::make('removeFromList')
            ->label('Remove from list')
            ->icon(Heroicon::OutlinedMinusCircle)
            ->visible(fn (): bool => auth()->user()->hasPermission(Permission::ManageProspects))
            ->schema([
                Select::make('contact_list_id')
                    ->label('List')
                    ->options(fn (): array => ContactList::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $list = ContactList::query()->findOrFail($data['contact_list_id']);
                $list->contacts()->detach($records->modelKeys());

                Notification::make()
                    ->success()
                    ->title('Contacts removed from '.$list->name)
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
