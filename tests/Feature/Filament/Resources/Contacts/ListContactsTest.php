<?php

use App\Enums\AudienceType;
use App\Enums\ConsentStatus;
use App\Enums\ContactStage;
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

it('hides import and bulk changes from viewers', function (string $bulkAction) {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListContacts::class)
        ->assertActionHidden('addFromText')
        ->assertTableBulkActionHidden($bulkAction);
})->with(['addToList', 'setStage', 'assignOwner']);

it('moves the selected contacts to a stage', function () {
    $this->actingAs(User::factory()->sender()->create());
    $selected = Contact::factory()->count(2)->create();
    $other = Contact::factory()->create();

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('setStage', $selected, data: ['stage' => ContactStage::Interested->value])
        ->assertHasNoTableBulkActionErrors();

    expect(Contact::query()->where('stage', ContactStage::Interested)->pluck('id')->sort()->values()->all())->toBe($selected->pluck('id')->sort()->values()->all())
        ->and($other->refresh()->stage)->toBe(ContactStage::New);
});

it('assigns and unassigns an owner for the selected contacts', function () {
    $this->actingAs(User::factory()->sender()->create());
    $owner = User::factory()->sender()->create();
    $contacts = Contact::factory()->count(2)->create();

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('assignOwner', $contacts, data: ['owner_id' => $owner->id])
        ->assertHasNoTableBulkActionErrors();

    expect(Contact::query()->whereBelongsTo($owner, 'owner')->count())->toBe(2);

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('assignOwner', $contacts->take(1), data: ['owner_id' => null])
        ->assertHasNoTableBulkActionErrors();

    expect($contacts->first()->refresh()->owner_id)->toBeNull()
        ->and($contacts->last()->refresh()->owner_id)->toBe($owner->id);
});

it('filters contacts by stage, industry, and details', function (string $filter, mixed $value) {
    $this->actingAs(User::factory()->create());
    $match = Contact::factory()->create(['stage' => ContactStage::Replied, 'industry' => 'Logistics', 'job_title' => 'Head of Operations', 'company' => 'Acme 100% Ltd', 'source' => 'Trade show']);
    $other = Contact::factory()->create(['stage' => ContactStage::New, 'industry' => 'Retail', 'job_title' => 'Buyer', 'company' => 'Acme 1000 Ltd', 'source' => 'Website']);

    Livewire::test(ListContacts::class)
        ->filterTable($filter, $value)
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
})->with([
    'stage' => ['stage', [ContactStage::Replied->value]],
    'industry' => ['industry', ['Logistics']],
    'job title' => ['details', ['job_title' => 'operations']],
    'company with a literal percent sign' => ['details', ['company' => '100%']],
    'source' => ['details', ['source' => 'trade']],
]);
