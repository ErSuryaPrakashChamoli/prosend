<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Actions\Contacts\AddContactsFromText;
use App\Enums\ConsentStatus;
use App\Filament\Exports\ContactExporter;
use App\Filament\Imports\ContactImporter;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\ContactList;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ImportAction::make()
                    ->label('Import CSV')
                    ->importer(ContactImporter::class)
                    ->icon(Heroicon::OutlinedArrowUpTray),
                $this->addFromTextAction(),
                ExportAction::make()
                    ->label('Export')
                    ->exporter(ContactExporter::class)
                    ->icon(Heroicon::OutlinedArrowDownTray),
            ])
                ->label('Import / export')
                ->icon(Heroicon::OutlinedArrowsUpDown)
                ->button()
                ->color('gray')
                ->visible(fn (): bool => ContactResource::canCreate()),
            CreateAction::make()
                ->label('Add contact'),
        ];
    }

    private function addFromTextAction(): Action
    {
        return Action::make('addFromText')
            ->label('Paste email addresses')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->modalDescription('Paste addresses separated by commas, spaces, or new lines. Lines like "Jane Doe <jane@example.com>" work too. Existing contacts keep their details.')
            ->schema([
                Textarea::make('addresses')
                    ->label('Email addresses')
                    ->rows(8)
                    ->required()
                    ->maxLength(500_000),
                Select::make('contact_list_id')
                    ->label('Add to list')
                    ->options(fn (): array => ContactList::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->placeholder('Do not add to a list'),
                Radio::make('consent_status')
                    ->label('Consent for new contacts')
                    ->options(ConsentStatus::class)
                    ->default(ConsentStatus::Unknown)
                    ->required()
                    ->live(),
                TextInput::make('consent_source')
                    ->label('Where did they give consent?')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $this->isGranted($get('consent_status')))
                    ->required(fn (Get $get): bool => $this->isGranted($get('consent_status'))),
                Checkbox::make('confirmed_lawful_basis')
                    ->label('I confirm these people asked to receive email from us. This is not a purchased, rented, or scraped list.')
                    ->accepted(),
            ])
            ->action(function (array $data, AddContactsFromText $addContacts): void {
                $result = $addContacts->handle(
                    text: $data['addresses'],
                    list: filled($data['contact_list_id'] ?? null) ? ContactList::query()->find($data['contact_list_id']) : null,
                    consent: $data['consent_status'] instanceof ConsentStatus ? $data['consent_status'] : ConsentStatus::from($data['consent_status']),
                    consentSource: $data['consent_source'] ?? null,
                );

                $body = Number::format($result['created']).' added, '.Number::format($result['existing']).' already existed.';

                if ($result['invalid'] !== []) {
                    $body .= ' Skipped '.count($result['invalid']).' invalid: '.implode(', ', array_slice($result['invalid'], 0, 10)).(count($result['invalid']) > 10 ? '…' : '');
                }

                if ($result['truncated']) {
                    $body .= ' Only the first '.Number::format(AddContactsFromText::MAX_ADDRESSES).' addresses were processed; use CSV import for larger lists.';
                }

                Notification::make()
                    ->title('Contacts added')
                    ->body($body)
                    ->status($result['invalid'] === [] ? 'success' : 'warning')
                    ->persistent($result['invalid'] !== [])
                    ->send();
            });
    }

    private function isGranted(mixed $state): bool
    {
        return ($state instanceof ConsentStatus ? $state : ConsentStatus::tryFrom((string) $state)) === ConsentStatus::Granted;
    }
}
