<?php

use App\Filament\Resources\Clients\Pages\ManageClients;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Scopes\ClientScope;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('lets a Super Admin add a client, with a URL name made from its name', function () {
    $this->actingAs($superAdmin = User::factory()->admin()->create());

    Livewire::test(ManageClients::class)
        ->callAction('create', data: ['name' => 'Acme Logistics', 'timezone' => 'Asia/Kolkata'])
        ->assertHasNoActionErrors();

    $client = Client::query()->where('name', 'Acme Logistics')->sole();
    expect($client)
        ->slug->toBe('acme-logistics')
        ->is_active->toBeTrue()
        ->created_by->toBe($superAdmin->id);
    expect(AuditLog::query()->withoutGlobalScope(ClientScope::class)->where('action', 'client.created')->sole()->client_id)->toBe($client->id);
});

it('gives clients with the same name different URL names', function () {
    $first = Client::factory()->create(['name' => 'Acme', 'slug' => null]);
    $second = Client::factory()->create(['name' => 'Acme', 'slug' => null]);

    expect([$first->slug, $second->slug])->toBe(['acme', 'acme-2']);
});

it('counts each client\'s own prospects, whichever client is open', function () {
    $this->actingAs(User::factory()->admin()->create());
    $other = Client::factory()->create();
    asClient($other, fn () => Contact::factory()->count(2)->create());
    Contact::factory()->create();

    Livewire::test(ManageClients::class)
        ->assertTableColumnStateSet('contacts_count', 2, $other)
        ->assertTableColumnStateSet('contacts_count', 1, Client::query()->where('slug', 'default')->sole());
});

it('deactivates and reactivates a client, and audits both', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    Livewire::test(ManageClients::class)->callAction(TestAction::make('deactivate')->table($client));
    expect($client->refresh())->is_active->toBeFalse()->deactivated_at->not->toBeNull();

    Livewire::test(ManageClients::class)->callAction(TestAction::make('activate')->table($client));
    expect($client->refresh())->is_active->toBeTrue()->deactivated_at->toBeNull();

    expect(AuditLog::query()->withoutGlobalScope(ClientScope::class)->where('client_id', $client->id)->pluck('action')->all())
        ->toBe(['client.deactivated', 'client.activated']);
});

it('keeps the clients page for Super Admins', function () {
    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(ManageClients::class)->assertForbidden();
});

it('never deletes a client', function () {
    expect(User::factory()->admin()->create()->can('delete', Client::factory()->create()))->toBeFalse()
        ->and(ApiKey::class)->toBeString();
});
