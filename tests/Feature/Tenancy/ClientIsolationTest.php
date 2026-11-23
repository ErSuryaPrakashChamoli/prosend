<?php

use App\Enums\AudienceType;
use App\Enums\CampaignStatus;
use App\Enums\ConsentStatus;
use App\Enums\EmailPurpose;
use App\Enums\MessageStatus;
use App\Enums\Permission;
use App\Enums\SuppressionReason;
use App\Enums\SystemRole;
use App\Filament\Imports\ContactImporter;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\PrepareCampaignMessages;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\Suppression;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignApprovalNotifier;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Sending\RecipientEligibility;
use App\Services\Tenancy\CurrentClient;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    $this->home = app(CurrentClient::class)->get();
    $this->other = Client::factory()->create(['name' => 'Other Co']);
});

it('only lists the current client\'s prospects', function () {
    $this->actingAs(User::factory()->sender()->create());
    $mine = Contact::factory()->create();
    $theirs = asClient($this->other, fn () => Contact::factory()->create());

    Livewire::test(ListContacts::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('refuses a member access to a client they do not belong to', function () {
    $this->actingAs(User::factory()->sender()->create());

    $this->get(ContactResource::getUrl(tenant: $this->other))->assertNotFound();
});

it('returns 404 for another client\'s record, even under the member\'s own client', function () {
    $this->actingAs(User::factory()->sender()->create());
    $theirs = asClient($this->other, fn () => Contact::factory()->create());

    $this->get(ContactResource::getUrl('view', ['record' => $theirs], tenant: $this->home))->assertNotFound();
});

it('lets a Super Admin open every client, and shows each client only its own data', function () {
    $this->actingAs(User::factory()->admin()->create());
    $theirs = asClient($this->other, fn () => Contact::factory()->create(['email' => 'theirs@example.com']));
    Contact::factory()->create(['email' => 'mine@example.com']);

    $this->get(ContactResource::getUrl(tenant: $this->other))
        ->assertOk()
        ->assertSee('theirs@example.com')
        ->assertDontSee('mine@example.com');

    $this->get(ContactResource::getUrl('view', ['record' => $theirs], tenant: $this->other))->assertOk();
});

it('keeps a deactivated client closed to its members', function () {
    $member = User::factory()->withoutClient()->create();
    asClient($this->other, fn () => UserFactory::addToClient($member, $this->other, SystemRole::CampaignManager));
    $this->other->deactivate();
    $this->actingAs($member->refreshRoles());

    $this->get(ContactResource::getUrl(tenant: $this->other))->assertNotFound();
    expect($member->hasPermission(Permission::ViewProspects, $this->other->refresh()))->toBeFalse();
});

it('allows the same email address as a prospect in two clients', function () {
    Contact::factory()->create(['email' => 'jane@example.com']);

    $copy = asClient($this->other, fn () => Contact::factory()->create(['email' => 'jane@example.com']));

    expect($copy->client_id)->toBe($this->other->id)
        ->and(Contact::acrossClients()->where('email', 'jane@example.com')->count())->toBe(2);
});

it('refuses to link a record to another client\'s record, even if a form is tampered with', function () {
    $theirSender = asClient($this->other, fn () => SenderIdentity::factory()->create());

    expect(fn () => Campaign::factory()->create(['sender_identity_id' => $theirSender->id]))
        ->toThrow(LogicException::class, 'The selected sender identity belongs to another client.');
});

it('refuses to move a record to another client', function () {
    $contact = Contact::factory()->create();

    expect(fn () => $contact->forceFill(['client_id' => $this->other->id])->save())
        ->toThrow(LogicException::class, 'cannot move to another client');
});

it('refuses to create client data when no client is current', function () {
    app(CurrentClient::class)->set(null);
    Filament\Facades\Filament::setTenant(null);

    expect(fn () => Contact::factory()->create())->toThrow(LogicException::class, 'must belong to a client');
});

it('prepares an "all contacts" campaign for its own client\'s prospects only, in a job with no client set', function () {
    Bus::fake([SendEmailMessage::class]);
    $campaign = Campaign::factory()->status(CampaignStatus::Queued)->create([
        'audience_type' => AudienceType::AllContacts,
        'purpose' => EmailPurpose::Marketing,
    ]);
    $mine = Contact::factory()->consented()->create();
    asClient($this->other, fn () => Contact::factory()->consented()->count(2)->create());

    // Queue workers start every job without a current client.
    app(CurrentClient::class)->set(null);
    Filament\Facades\Filament::setTenant(null);
    (new PrepareCampaignMessages($campaign))->handle(
        app(CampaignDispatcher::class),
        app(CampaignApprovalNotifier::class),
        app(AuditLogger::class),
    );

    expect(EmailMessage::acrossClients()->pluck('contact_id')->all())->toBe([$mine->id])
        ->and(EmailMessage::acrossClients()->sole()->client_id)->toBe($this->home->id);
});

it('checks suppressions within the sending client only', function () {
    asClient($this->other, fn () => Suppression::record('jane@example.com', SuppressionReason::Unsubscribed, 'test'));
    Contact::factory()->consented()->create(['email' => 'jane@example.com']);

    expect(app(RecipientEligibility::class)->blockReason('jane@example.com', EmailPurpose::Marketing))->toBeNull();

    asClient($this->other, function () {
        expect(app(RecipientEligibility::class)->blockReason('jane@example.com', EmailPurpose::Transactional))->toBeNull()
            ->and(app(RecipientEligibility::class)->blockReason('jane@example.com', EmailPurpose::Marketing))->toContain('suppressed');
    });
});

it('records an unsubscribe in the client that sent the email', function () {
    $message = asClient($this->other, fn () => EmailMessage::factory()->create(['purpose' => EmailPurpose::Marketing, 'to_email' => 'jane@example.com']));

    $this->post($message->unsubscribeUrl())->assertOk();

    expect(Suppression::acrossClients()->sole())
        ->client_id->toBe($this->other->id)
        ->email->toBe('jane@example.com');
});

it('applies Brevo events to the provider\'s own client', function () {
    $provider = asClient($this->other, fn () => SmtpProvider::factory()->create());
    $token = $provider->regenerateWebhookToken();
    $message = asClient($this->other, fn () => EmailMessage::factory()
        ->for(SenderIdentity::factory()->for($provider))
        ->accepted()
        ->create(['smtp_provider_id' => $provider->id, 'to_email' => 'jane@example.com', 'provider_message_id' => 'abc@acme.test']));

    $this->postJson(route('webhooks.brevo', ['provider' => $provider, 'token' => $token]), [
        'event' => 'hard_bounce', 'email' => 'jane@example.com', 'message-id' => '<abc@acme.test>', 'ts_event' => 1793000000,
    ])->assertNoContent();

    expect($message->refresh()->status)->toBe(MessageStatus::Bounced)
        ->and(Suppression::acrossClients()->sole()->client_id)->toBe($this->other->id);
});

it('limits an API key to its own client\'s emails', function () {
    [$apiKey, $plaintext] = issueApiKey(['scopes' => ['emails:read', 'emails:read_all']]);
    $mine = EmailMessage::factory()->create(['api_key_id' => $apiKey->id]);
    $theirs = asClient($this->other, fn () => EmailMessage::factory()->create());

    $this->withToken($plaintext)->getJson('/api/v1/emails')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->uuid);

    $this->withToken($plaintext)->getJson("/api/v1/emails/{$theirs->uuid}")->assertNotFound();
});

it('refuses API keys of a deactivated client', function () {
    [, $plaintext] = issueApiKey();
    $this->home->deactivate();

    $this->withToken($plaintext)->getJson('/api/v1/me')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'client_inactive');
});

it('writes the current client into export queries, which run in a job with no client set', function () {
    $query = AppServiceProvider::limitToCurrentClient(Contact::query());
    Contact::factory()->create();
    asClient($this->other, fn () => Contact::factory()->create());

    app(CurrentClient::class)->set(null);
    Filament\Facades\Filament::setTenant(null);

    expect($query->count())->toBe(1)
        ->and(AppServiceProvider::limitToCurrentClient(Contact::query())->count())->toBe(0);
});

it('imports into the client that started the import even when the queue worker has no client set', function () {
    $home = $this->home;
    $theirs = asClient($this->other, fn () => Contact::factory()->create(['email' => 'jane@example.com', 'first_name' => 'Theirs']));
    $theirList = asClient($this->other, fn () => ContactList::factory()->create());

    app(CurrentClient::class)->set(null);
    Filament\Facades\Filament::setTenant(null);

    ContactImporter::test(['email' => 'Email', 'first_name' => 'First name'], [
        'client_id' => $home->id,
        'contact_list_id' => $theirList->id,
        'default_consent_status' => ConsentStatus::Granted->value,
        'consent_source' => 'Form',
    ])
        ->import(['Email' => 'jane@example.com', 'First name' => 'Mine'])->assertImported()
        ->import(['Email' => 'sam@example.com', 'First name' => 'Sam'])->assertImported();

    expect($theirs->refresh())
        ->first_name->toBe('Theirs')
        ->consent_status->toBe(ConsentStatus::Unknown);
    expect(Contact::acrossClients()->where('client_id', $home->id)->orderBy('email')->pluck('first_name', 'email')->all())
        ->toBe(['jane@example.com' => 'Mine', 'sam@example.com' => 'Sam']);
    expect($theirList->contacts()->count())->toBe(0);
});

it('only offers the client\'s own members as prospect owners', function () {
    $this->actingAs(User::factory()->sender()->create());
    $colleague = User::factory()->create(['name' => 'Colleague']);
    $outsider = User::factory()->withoutClient()->create(['name' => 'Outsider']);
    UserFactory::addToClient($outsider, $this->other, SystemRole::Admin);
    $contact = Contact::factory()->create();

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('assignOwner', [$contact], data: ['owner_id' => $outsider->id])
        ->assertHasTableBulkActionErrors(['owner_id']);

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('assignOwner', [$contact], data: ['owner_id' => $colleague->id])
        ->assertHasNoTableBulkActionErrors();

    expect($contact->refresh()->owner_id)->toBe($colleague->id);
});
