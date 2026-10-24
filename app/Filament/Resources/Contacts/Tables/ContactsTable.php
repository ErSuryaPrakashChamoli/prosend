<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Enums\AudienceType;
use App\Enums\ConsentStatus;
use App\Enums\EmailPurpose;
use App\Filament\Pages\ComposeEmail;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Segment;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lists', 'suppression']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')
                    ->label('Email address')
                    ->description(fn (Contact $record): ?string => $record->full_name)
                    ->searchable(['email', 'first_name', 'last_name'])
                    ->sortable()
                    ->copyable(),
                TextColumn::make('company')
                    ->searchable()
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
            ->visible(fn (): bool => auth()->user()->role->canManageAudiences())
            ->schema([
                Select::make('contact_list_id')
                    ->label('List')
                    ->options(fn (): array => ContactList::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->createOptionForm([
                        TextInput::make('name')->required()->maxLength(255)->unique('contact_lists', 'name'),
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

    private static function removeFromListAction(): BulkAction
    {
        return BulkAction::make('removeFromList')
            ->label('Remove from list')
            ->icon(Heroicon::OutlinedMinusCircle)
            ->visible(fn (): bool => auth()->user()->role->canManageAudiences())
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
