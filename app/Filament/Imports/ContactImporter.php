<?php

namespace App\Filament\Imports;

use App\Enums\ConsentStatus;
use App\Enums\OutreachEvent;
use App\Jobs\Middleware\RunAsClient;
use App\Models\Contact;
use App\Models\ContactList;
use App\Services\Automation\OutreachEvents;
use App\Services\Tenancy\CurrentClient;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use LogicException;

/**
 * Imports contacts from CSV. Existing contacts (matched by email) are updated only where the file has a
 * value, and an import can never undo an unsubscribe or a suppression.
 */
class ContactImporter extends Importer
{
    protected static ?string $model = Contact::class;

    /**
     * The failed-rows CSV repeats uploaded cells, so cells that a spreadsheet would run as formulas are neutralised.
     */
    protected static bool $shouldPreventFormulaInjection = true;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('email')
                ->label('Email address')
                ->requiredMapping()
                ->guess(['email', 'email address', 'e-mail', 'mail'])
                ->example('jane@example.com')
                ->castStateUsing(fn (?string $state): ?string => static::withoutFormulaEscape($state))
                ->rules(['required', 'email:rfc', 'max:255']),
            static::optionalTextColumn('first_name', ['first name', 'firstname', 'given name'], 'Jane'),
            static::optionalTextColumn('last_name', ['last name', 'lastname', 'surname', 'family name'], 'Doe'),
            static::optionalTextColumn('company', ['company name', 'organization', 'organisation'], 'Example Ltd'),
            static::optionalTextColumn('job_title', ['job title', 'title', 'position', 'role'], 'Head of Operations'),
            static::optionalTextColumn('industry', ['sector'], 'Logistics'),
            static::optionalTextColumn('website', ['website url', 'url', 'web', 'company website'], 'https://example.com')
                ->castStateUsing(fn (?string $state): ?string => Contact::normalizeWebsite(static::withoutFormulaEscape($state)))
                ->rules(['nullable', 'string', 'max:255', 'url:http,https']),
            static::optionalTextColumn('phone', ['phone number', 'mobile', 'telephone'], '+1 555 0100', maxLength: 50),
            static::optionalTextColumn('timezone', ['time zone', 'tz'], 'Asia/Kolkata', maxLength: 64)
                ->rules(['nullable', 'string', 'timezone:all']),
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
                ->required(fn (Get $get): bool => static::consentOption($get('default_consent_status')) === ConsentStatus::Granted),
            Checkbox::make('confirmed_lawful_basis')
                ->label('I confirm these contacts asked to receive email from us. This is not a purchased, rented, or scraped list.')
                ->accepted(),
        ];
    }

    /**
     * Imports run on the queue. The client that started the import travels in its options, and every chunk runs as that
     * client, so rows can only ever match or create that client's contacts.
     *
     * @return array<int, object>
     */
    public function getJobMiddleware(): array
    {
        return [...parent::getJobMiddleware(), new RunAsClient($this->clientId())];
    }

    public function resolveRecord(): Contact
    {
        $email = Str::lower(trim((string) $this->data['email']));

        return Contact::acrossClients()->where('client_id', $this->clientId())->where('email', $email)->first()
            ?? (new Contact)->forceFill(['client_id' => $this->clientId(), 'email' => $email]);
    }

    /**
     * Imports started before clients were recorded in the options fall back to the tenant Filament restores.
     */
    private function clientId(): int
    {
        return (int) ($this->options['client_id']
            ?? app(CurrentClient::class)->id()
            ?? throw new LogicException('This import does not say which client it is for. Start it again from the contacts page.'));
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
        if ($this->record->wasRecentlyCreated) {
            app(OutreachEvents::class)->dispatch(OutreachEvent::ProspectImported, $this->record->client_id, $this->record, ['source' => $this->record->source], "import:{$this->record->getKey()}");
        }

        $listId = (int) ($this->options['contact_list_id'] ?? 0);

        if ($listId > 0 && ContactList::acrossClients()->where('client_id', $this->clientId())->whereKey($listId)->exists()) {
            $this->record->lists()->syncWithoutDetaching([$listId]);
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

        $status = $state ?? static::consentOption($options['default_consent_status'] ?? null) ?? ConsentStatus::Unknown;

        if ($status === $record->consent_status && $record->exists) {
            return;
        }

        $record->consent_status = $status;
        $record->consent_source = $status === ConsentStatus::Granted ? ($options['consent_source'] ?? 'CSV import') : $record->consent_source;
        $record->consented_at = $status === ConsentStatus::Granted ? now() : $record->consented_at;
    }

    /**
     * The form gives the chosen consent as an enum, while stored or test options may hold its string value.
     */
    protected static function consentOption(mixed $option): ?ConsentStatus
    {
        return $option instanceof ConsentStatus ? $option : ConsentStatus::tryFrom((string) $option);
    }

    /**
     * ProSend's exports put an apostrophe before cells that start like a formula, such as "+44 20 7946 0000".
     * Removing it again lets an exported file be imported without changing the data.
     */
    public static function withoutFormulaEscape(?string $state): ?string
    {
        return $state === null ? null : preg_replace('/^\'(?=[=+\-@\t\r])/', '', $state);
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
            ->castStateUsing(fn (?string $state): ?string => static::withoutFormulaEscape($state))
            ->rules(['nullable', 'string', "max:{$maxLength}"])
            ->fillRecordUsing(function (Contact $record, ?string $state) use ($name): void {
                if (filled($state)) {
                    $record->{$name} = trim($state);
                }
            });
    }
}
