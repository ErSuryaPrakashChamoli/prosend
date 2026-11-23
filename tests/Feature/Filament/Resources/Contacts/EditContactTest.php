<?php

use App\Enums\ContactStage;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

it('saves the prospect details', function () {
    $this->actingAs(User::factory()->sender()->create());
    $owner = User::factory()->sender()->create();
    $contact = Contact::factory()->create();

    Livewire::test(EditContact::class, ['record' => $contact->getRouteKey()])
        ->fillForm([
            'job_title' => 'Head of Operations',
            'industry' => 'Logistics',
            'website' => 'https://acme.test',
            'stage' => ContactStage::Replied->value,
            'owner_id' => $owner->id,
            'last_replied_at' => '2026-10-28 09:30:00',
            'notes' => 'Asked for pricing in November.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh())
        ->job_title->toBe('Head of Operations')
        ->industry->toBe('Logistics')
        ->website->toBe('https://acme.test')
        ->stage->toBe(ContactStage::Replied)
        ->owner_id->toBe($owner->id)
        ->last_replied_at->toDateTimeString()->toBe('2026-10-28 09:30:00')
        ->notes->toBe('Asked for pricing in November.');
});

it('rejects a website that is not an http or https address', function (string $website) {
    $this->actingAs(User::factory()->sender()->create());
    $contact = Contact::factory()->create();

    Livewire::test(EditContact::class, ['record' => $contact->getRouteKey()])
        ->fillForm(['website' => $website])
        ->call('save')
        ->assertHasFormErrors(['website']);

    expect($contact->refresh()->website)->toBeNull();
})->with(['javascript:alert(1)', 'mailto:jane@example.com']);

it('adds https to a website typed without a scheme', function () {
    $this->actingAs(User::factory()->sender()->create());
    $contact = Contact::factory()->create();

    Livewire::test(EditContact::class, ['record' => $contact->getRouteKey()])
        ->fillForm(['website' => 'acme.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh()->website)->toBe('https://acme.test');
});

it('keeps a stage that sending set while the form was open', function () {
    $this->actingAs(User::factory()->sender()->create());
    $contact = Contact::factory()->create(['stage' => ContactStage::New]);
    $page = Livewire::test(EditContact::class, ['record' => $contact->getRouteKey()]);

    Contact::recordContacted($contact->id);
    $page->fillForm(['notes' => 'Fixed a typo'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh())
        ->stage->toBe(ContactStage::Contacted)
        ->last_contacted_at->not->toBeNull()
        ->notes->toBe('Fixed a typo');
});
