<?php

namespace App\Filament\Imports;

use App\Enums\ConsentStatus;
use App\Models\Contact;
use App\Models\ContactList;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Imports contacts from CSV. Existing contacts (matched by email) are updated only where the file has a
 * value, and an import can never undo an unsubscribe or a suppression.
 */
class ContactImporter extends Importer
{
    protected static ?string $model = Contact::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('email')
                ->label('Email address')
                ->requiredMapping()
                ->guess(['email', 'email address', 'e-mail', 'mail'])
                ->example('jane@example.com')
                ->rules(['required', 'email:rfc', 'max:255']),
            static::optionalTextColumn('first_name', ['first name', 'firstname', 'given name'], 'Jane'),
            static::optionalTextColumn('last_name', ['last name', 'lastname', 'surname', 'family name'], 'Doe'),
            static::optionalTextColumn('company', ['company name', 'organization', 'organisation'], 'Example Ltd'),
            static::optionalTextColumn('phone', ['phone number', 'mobile', 'telephone'], '+1 555 0100', maxLength: 50),
            ImportColumn::make('tags')
                ->array(',')
                ->example('customer,newsletter')
                ->helperText('Comma-separated. Added to any tags the contact already has.')
                ->nestedRecursiveRules(['string', 'max:50'])
                ->fillRecordUsing(function (Contact $record, array $state): void {
                    $record->tags = array_values(array_unique([...($record->tags ?? []), ...array_filter(array_map('trim', $state))]));
                }),
            ImportColumn::make('consent_status')
                ->label('Consent')
                ->guess(['consent', 'opt in', 'opted in', 'subscribed', 'marketing consent'])
                ->example('yes')
                ->helperText('Values such as "yes", "granted", or "opted in" record consent. Leave unmapped to use the default below.')
                ->castStateUsing(fn (?string $state): ?ConsentStatus => static::parseConsent($state))
                ->fillRecordUsing(function (Contact $record, ?ConsentStatus $state, array $options): void {
                    static::fillConsent($record, $state, $options);
                }),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('contact_list_id')
                ->label('Add imported contacts to list')
                ->options(fn (): array => ContactList::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->placeholder('Do not add to a list'),
            Select::make('default_consent_status')
                ->label('Consent for new contacts without a consent value')
                ->options(ConsentStatus::class)
                ->default(ConsentStatus::Unknown->value)
                ->required()
                ->live(),
            TextInput::make('consent_source')
                ->label('Where did these contacts give consent?')
                ->placeholder('For example: website newsletter form, March 2026')
                ->maxLength(255)
                ->required(fn (Get $get): bool => $get('default_consent_status') === ConsentStatus::Granted->value),
            Checkbox::make('confirmed_lawful_basis')
                ->label('I confirm these contacts asked to receive email from us. This is not a purchased, rented, or scraped list.')
                ->accepted(),
        ];
    }

    public function resolveRecord(): Contact
    {
        return Contact::query()->firstOrNew([
            'email' => Str::lower(trim((string) $this->data['email'])),
        ]);
    }

    protected function beforeCreate(): void
    {
        $this->record->source ??= 'CSV import';

        if (! isset($this->columnMap['consent_status']) || blank($this->originalData[$this->columnMap['consent_status']] ?? null)) {
            static::fillConsent($this->record, null, $this->options);
        }
    }

    protected function afterSave(): void
    {
        if (filled($this->options['contact_list_id'] ?? null)) {
            $this->record->lists()->syncWithoutDetaching([(int) $this->options['contact_list_id']]);
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your contact import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    public static function parseConsent(?string $value): ?ConsentStatus
    {
        $value = Str::lower(trim((string) $value));

        return match (true) {
            $value === '' => null,
            in_array($value, ['yes', 'y', 'true', '1', 'granted', 'opted in', 'opt-in', 'subscribed'], true) => ConsentStatus::Granted,
            in_array($value, ['no', 'n', 'false', '0', 'revoked', 'withdrawn', 'opted out', 'opt-out'], true) => ConsentStatus::Revoked,
            default => ConsentStatus::Unknown,
        };
    }

    /**
     * A file value always applies. Without one, new contacts get the chosen default and existing ones keep theirs.
     *
     * @param  array<string, mixed>  $options
     */
    protected static function fillConsent(Contact $record, ?ConsentStatus $state, array $options): void
    {
        if ($state === null && $record->exists) {
            return;
        }

        $status = $state ?? ConsentStatus::tryFrom((string) ($options['default_consent_status'] ?? '')) ?? ConsentStatus::Unknown;

        if ($status === $record->consent_status && $record->exists) {
            return;
        }

        $record->consent_status = $status;
        $record->consent_source = $status === ConsentStatus::Granted ? ($options['consent_source'] ?? 'CSV import') : $record->consent_source;
        $record->consented_at = $status === ConsentStatus::Granted ? now() : $record->consented_at;
    }

    /**
     * A text column that only overwrites existing data when the file has a value.
     *
     * @param  list<string>  $guesses
     */
    protected static function optionalTextColumn(string $name, array $guesses, string $example, int $maxLength = 255): ImportColumn
    {
        return ImportColumn::make($name)
            ->guess($guesses)
            ->example($example)
            ->rules(['nullable', 'string', "max:{$maxLength}"])
            ->fillRecordUsing(function (Contact $record, ?string $state) use ($name): void {
                if (filled($state)) {
                    $record->{$name} = trim($state);
                }
            });
    }
}
