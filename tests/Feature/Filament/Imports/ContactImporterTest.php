<?php

use App\Enums\ConsentStatus;
use App\Filament\Imports\ContactImporter;
use App\Models\Contact;
use App\Models\ContactList;

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
