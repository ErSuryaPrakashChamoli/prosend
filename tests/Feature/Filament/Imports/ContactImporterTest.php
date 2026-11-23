<?php

use App\Enums\ConsentStatus;
use App\Filament\Imports\ContactImporter;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\User;
use Filament\Actions\Imports\ContentGenerators\CsvImportFailureContentGenerator;
use Filament\Actions\Imports\Models\Import;
use League\Csv\Writer;

it('creates a contact with the default consent and adds it to the chosen list', function () {
    $list = ContactList::factory()->create();

    ContactImporter::test(['email' => 'Email', 'first_name' => 'First name'], [
        'contact_list_id' => $list->id,
        'default_consent_status' => ConsentStatus::Granted->value,
        'consent_source' => 'Website form',
    ])->import(['Email' => ' Jane@Example.com ', 'First name' => 'Jane'])->assertImported();

    $contact = Contact::query()->sole();
    expect($contact)
        ->email->toBe('jane@example.com')
        ->first_name->toBe('Jane')
        ->source->toBe('CSV import')
        ->consent_status->toBe(ConsentStatus::Granted)
        ->consent_source->toBe('Website form');
    expect($contact->lists->pluck('id')->all())->toBe([$list->id]);
});

it('keeps existing details when the file leaves a column empty', function () {
    $contact = Contact::factory()->create(['email' => 'jane@example.com', 'first_name' => 'Jane', 'company' => 'Acme', 'consent_status' => ConsentStatus::Granted]);

    ContactImporter::test(['email' => 'Email', 'first_name' => 'First name', 'company' => 'Company'], [
        'default_consent_status' => ConsentStatus::Unknown->value,
    ])->import(['Email' => 'jane@example.com', 'First name' => '', 'Company' => 'Acme Ltd'])->assertImported();

    expect($contact->refresh())
        ->first_name->toBe('Jane')
        ->company->toBe('Acme Ltd')
        ->consent_status->toBe(ConsentStatus::Granted);
});

it('cannot resubscribe a contact who unsubscribed', function () {
    $contact = Contact::factory()->unsubscribed()->create(['email' => 'left@example.com']);

    ContactImporter::test(['email' => 'Email', 'consent_status' => 'Consent'], [
        'default_consent_status' => ConsentStatus::Unknown->value,
    ])->import(['Email' => 'left@example.com', 'Consent' => 'yes'])->assertImported();

    expect($contact->refresh()->unsubscribed_at)->not->toBeNull();
});

it('rejects a row with an invalid email address', function () {
    ContactImporter::test(['email' => 'Email'], ['default_consent_status' => ConsentStatus::Unknown->value])
        ->import(['Email' => 'not an email'])
        ->assertHasErrors(['email']);

    expect(Contact::query()->count())->toBe(0);
});

it('understands common consent values', function (string $value, ?ConsentStatus $expected) {
    expect(ContactImporter::parseConsent($value))->toBe($expected);
})->with([
    'yes' => ['Yes', ConsentStatus::Granted],
    'opted in' => ['opted in', ConsentStatus::Granted],
    'no' => ['no', ConsentStatus::Revoked],
    'unrecognised' => ['maybe', ConsentStatus::Unknown],
    'blank' => ['', null],
]);

it('imports prospect details and adds https to a website without a scheme', function () {
    ContactImporter::test(['email' => 'Email', 'job_title' => 'Title', 'industry' => 'Industry', 'website' => 'Website'], [
        'default_consent_status' => ConsentStatus::Unknown->value,
    ])->import(['Email' => 'jane@example.com', 'Title' => 'Head of Operations', 'Industry' => 'Logistics', 'Website' => 'acme.test'])->assertImported();

    expect(Contact::query()->sole())
        ->job_title->toBe('Head of Operations')
        ->industry->toBe('Logistics')
        ->website->toBe('https://acme.test');
});

it('rejects a website that is not an http or https address', function () {
    ContactImporter::test(['email' => 'Email', 'website' => 'Website'], ['default_consent_status' => ConsentStatus::Unknown->value])
        ->import(['Email' => 'jane@example.com', 'Website' => 'javascript:alert(1)'])
        ->assertHasErrors(['website']);

    expect(Contact::query()->count())->toBe(0);
});

it('removes the apostrophe that exports put before formula-like cells', function () {
    ContactImporter::test(['email' => 'Email', 'phone' => 'Phone', 'company' => 'Company'], [
        'default_consent_status' => ConsentStatus::Unknown->value,
    ])->import(['Email' => 'jane@example.com', 'Phone' => "'+44 20 7946 0000", 'Company' => "O'Reilly"])->assertImported();

    expect(Contact::query()->sole())
        ->phone->toBe('+44 20 7946 0000')
        ->company->toBe("O'Reilly");
});

it('neutralises formulas in the failed-rows file', function () {
    $import = Import::query()->create([
        'file_name' => 'contacts.csv',
        'file_path' => 'imports/contacts.csv',
        'importer' => ContactImporter::class,
        'total_rows' => 1,
        'user_id' => User::factory()->create()->id,
    ]);
    $import->failedRows()->create([
        'data' => ['Email' => 'not an email', 'First name' => '=HYPERLINK("https://evil.test","Click")'],
        'validation_error' => 'The email address field must be a valid email address.',
    ]);
    $csv = Writer::fromString();

    app(CsvImportFailureContentGenerator::class)($import, $csv);

    expect($csv->toString())
        ->toContain('"\'=HYPERLINK(')
        ->not->toContain(',"=HYPERLINK(');
});

it('matches an exported email address that starts with a formula character to the existing contact', function () {
    $contact = Contact::factory()->create(['email' => '+sales@example.com', 'first_name' => 'Old']);

    ContactImporter::test(['email' => 'Email', 'first_name' => 'First name'], ['default_consent_status' => ConsentStatus::Unknown->value])
        ->import(['Email' => "'+sales@example.com", 'First name' => 'New'])
        ->assertImported();

    expect(Contact::query()->count())->toBe(1)
        ->and($contact->refresh()->first_name)->toBe('New');
});

it('treats a website port as part of the address, not a scheme', function () {
    ContactImporter::test(['email' => 'Email', 'website' => 'Website'], ['default_consent_status' => ConsentStatus::Unknown->value])
        ->import(['Email' => 'jane@example.com', 'Website' => 'acme.test:8080'])
        ->assertImported();

    expect(Contact::query()->sole()->website)->toBe('https://acme.test:8080');
});
