<?php

use App\Actions\Contacts\AddContactsFromText;
use App\Enums\ConsentStatus;
use App\Models\Contact;
use App\Models\ContactList;

it('adds new addresses, skips invalid ones, and adds everyone to the list', function () {
    $list = ContactList::factory()->create();
    $existing = Contact::factory()->create(['email' => 'existing@example.com', 'consent_status' => ConsentStatus::Revoked]);

    $result = app(AddContactsFromText::class)->handle(
        "Jane Doe <Jane@Example.com>, sam@example.com\nexisting@example.com; jane@example.com not-an-email@ @nope",
        $list,
        ConsentStatus::Granted,
        'Event signup sheet',
    );

    expect($result)->toBe(['created' => 2, 'existing' => 1, 'invalid' => [], 'truncated' => false]);
    expect(Contact::query()->where('email', 'jane@example.com')->sole())
        ->consent_status->toBe(ConsentStatus::Granted)
        ->consent_source->toBe('Event signup sheet')
        ->consented_at->not->toBeNull();
    expect($existing->refresh()->consent_status)->toBe(ConsentStatus::Revoked);
    expect($list->contacts()->pluck('email')->sort()->values()->all())->toBe(['existing@example.com', 'jane@example.com', 'sam@example.com']);
});

it('reports addresses that are not valid', function () {
    $result = app(AddContactsFromText::class)->handle('good@example.com bad@example..com');

    expect($result['invalid'])->toBe(['bad@example..com']);
    expect(Contact::query()->pluck('email')->all())->toBe(['good@example.com']);
});
