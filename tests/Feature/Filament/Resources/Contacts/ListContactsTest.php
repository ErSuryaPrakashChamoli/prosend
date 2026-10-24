<?php

use App\Enums\AudienceType;
use App\Enums\ConsentStatus;
use App\Enums\EmailPurpose;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\User;
use Livewire\Livewire;

it('adds pasted email addresses as contacts', function () {
    $this->actingAs(User::factory()->sender()->create());
    $list = ContactList::factory()->create();

    Livewire::test(ListContacts::class)
        ->callAction('addFromText', data: [
            'addresses' => "jane@example.com\nsam@example.com, bad@",
            'contact_list_id' => $list->id,
            'consent_status' => ConsentStatus::Unknown->value,
            'confirmed_lawful_basis' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Contacts added');

    expect($list->contacts()->pluck('email')->sort()->values()->all())->toBe(['jane@example.com', 'sam@example.com']);
});

it('requires confirming the lawful basis before adding contacts', function () {
    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(ListContacts::class)
        ->callAction('addFromText', data: [
            'addresses' => 'jane@example.com',
            'consent_status' => ConsentStatus::Unknown->value,
            'confirmed_lawful_basis' => false,
        ])
        ->assertHasActionErrors(['confirmed_lawful_basis' => 'accepted']);

    expect(Contact::query()->count())->toBe(0);
});

it('creates a draft campaign for the selected contacts', function () {
    $this->actingAs(User::factory()->sender()->create());
    $contacts = Contact::factory()->count(2)->create();
    Contact::factory()->create();

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('createCampaign', $contacts, data: [
            'name' => 'Spring update',
            'purpose' => EmailPurpose::Marketing->value,
        ])
        ->assertHasNoTableBulkActionErrors();

    $campaign = Campaign::query()->sole();
    expect($campaign)
        ->name->toBe('Spring update')
        ->audience_type->toBe(AudienceType::SelectedContacts);
    expect($campaign->selectedContacts()->pluck('contacts.id')->sort()->values()->all())->toBe($contacts->pluck('id')->sort()->values()->all());
});

it('hides import and bulk changes from viewers', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListContacts::class)
        ->assertActionHidden('addFromText')
        ->assertTableBulkActionHidden('addToList');
});
