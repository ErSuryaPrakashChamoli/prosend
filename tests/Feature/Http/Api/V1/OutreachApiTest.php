<?php

use App\Enums\ApiKeyMode;
use App\Enums\AudienceType;
use App\Enums\ConversationStatus;
use App\Enums\EmailPurpose;
use App\Enums\SuppressionReason;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Conversation;
use App\Models\SenderIdentity;
use App\Models\Suppression;

function outreachKey(array $scopes): string
{
    return issueApiKey(['scopes' => $scopes], SenderIdentity::query()->where('email', 'billing@acme.test')->first())[1];
}

it('creates a prospect, then updates it without losing what it already had', function () {
    $list = ContactList::factory()->create();
    $key = outreachKey(['prospects:write']);

    $this->withToken($key)->putJson('/api/v1/prospects', [
        'email' => 'Jane@Example.com',
        'first_name' => 'Jane',
        'tags' => ['webinar'],
        'consent_status' => 'granted',
        'consent_source' => 'Webinar signup',
        'list_ids' => [$list->id],
    ])->assertCreated()->assertJsonPath('data.email', 'jane@example.com')->assertJsonPath('meta.created', true);

    $this->withToken($key)->putJson('/api/v1/prospects', ['email' => 'jane@example.com', 'company' => 'Acme', 'tags' => ['vip']])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Jane')
        ->assertJsonPath('data.company', 'Acme')
        ->assertJsonPath('data.tags', ['webinar', 'vip'])
        ->assertJsonPath('data.consent.status', 'granted')
        ->assertJsonPath('data.list_ids', [$list->id]);
});

it('requires a consent source when granting consent, and never lifts an unsubscribe', function () {
    $key = outreachKey(['prospects:write']);
    $contact = Contact::factory()->unsubscribed()->create(['email' => 'gone@example.com']);
    Suppression::record('gone@example.com', SuppressionReason::Unsubscribed, 'test');

    $this->withToken($key)->putJson('/api/v1/prospects', ['email' => 'new@example.com', 'consent_status' => 'granted'])
        ->assertUnprocessable();

    $this->withToken($key)->putJson('/api/v1/prospects', ['email' => 'gone@example.com', 'consent_status' => 'granted', 'consent_source' => 'CRM'])->assertOk();

    expect($contact->refresh()->unsubscribed_at)->not->toBeNull()
        ->and(Suppression::query()->where('email', 'gone@example.com')->exists())->toBeTrue()
        ->and($contact->eligibleFor(EmailPurpose::Marketing)->exists())->toBeFalse();
});

it('records an unsubscribe', function () {
    $contact = Contact::factory()->consented()->create();

    $this->withToken(outreachKey(['prospects:write']))->postJson("/api/v1/prospects/{$contact->id}/unsubscribe")->assertOk();

    expect(Suppression::query()->sole()->reason)->toBe(SuppressionReason::Unsubscribed)
        ->and($contact->refresh()->unsubscribed_at)->not->toBeNull();
});

it('needs the right scope, and write includes read', function () {
    $contact = Contact::factory()->create();

    $this->withToken(outreachKey(['emails:read']))->getJson('/api/v1/prospects')->assertForbidden()->assertJsonPath('error.code', 'missing_scope');
    $this->withToken(outreachKey(['prospects:read']))->putJson('/api/v1/prospects', ['email' => 'x@example.com'])->assertForbidden();
    $this->withToken(outreachKey(['prospects:write']))->getJson("/api/v1/prospects/{$contact->id}")->assertOk();
});

it('never lets a test key change prospects, even one given the scope before the rule existed', function () {
    $key = issueApiKey(['mode' => ApiKeyMode::Test, 'scopes' => ['prospects:write']], SenderIdentity::query()->where('email', 'billing@acme.test')->first())[1];

    $this->withToken($key)->putJson('/api/v1/prospects', ['email' => 'x@example.com'])->assertForbidden();
    $this->withToken($key)->getJson('/api/v1/prospects')->assertOk();

    expect(Contact::query()->where('email', 'x@example.com')->exists())->toBeFalse();
});

it('only ever shows the key\'s own client\'s data', function () {
    $other = Client::factory()->create();
    $theirContact = asClient($other, fn () => Contact::factory()->create());
    $theirCampaign = asClient($other, fn () => Campaign::factory()->create());
    $theirList = asClient($other, fn () => ContactList::factory()->create());
    $key = outreachKey(['prospects:write', 'campaigns:read']);

    $this->withToken($key)->getJson("/api/v1/prospects/{$theirContact->id}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/campaigns/{$theirCampaign->id}")->assertNotFound();
    $this->withToken($key)->putJson('/api/v1/prospects', ['email' => 'a@example.com', 'list_ids' => [$theirList->id]])->assertUnprocessable();
    $this->withToken($key)->getJson('/api/v1/prospects')->assertJsonCount(0, 'data');
});

it('reads campaigns with their prospects\' progress', function () {
    $campaign = Campaign::factory()->prepared()->create(['name' => 'Q4']);
    CampaignProspect::query()->create(['campaign_id' => $campaign->id, 'contact_id' => Contact::factory()->create()->id]);

    $this->withToken(outreachKey(['campaigns:read']))->getJson("/api/v1/campaigns/{$campaign->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Q4')
        ->assertJsonPath('data.status', 'sending')
        ->assertJsonPath('data.prospects.active', 1);
});

it('adds prospects to a draft sent to selected prospects, and to nothing else', function () {
    $draft = Campaign::factory()->create(['audience_type' => AudienceType::SelectedContacts]);
    $running = Campaign::factory()->prepared()->create(['audience_type' => AudienceType::SelectedContacts]);
    $jane = Contact::factory()->create(['email' => 'jane@example.com']);
    $sam = Contact::factory()->create();
    $key = outreachKey(['campaigns:write']);

    $this->withToken($key)->postJson("/api/v1/campaigns/{$draft->id}/prospects", ['emails' => ['Jane@example.com'], 'prospect_ids' => [$sam->id]])
        ->assertOk()
        ->assertJsonPath('meta.added', 2);
    $this->withToken($key)->postJson("/api/v1/campaigns/{$running->id}/prospects", ['prospect_ids' => [$jane->id]])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'campaign_not_editable');

    expect($draft->selectedContacts()->pluck('contacts.id')->sort()->values()->all())->toBe([$jane->id, $sam->id])
        ->and($running->selectedContacts()->count())->toBe(0);
});

it('reads conversations with their messages', function () {
    $conversation = Conversation::factory()->create(['status' => ConversationStatus::Interested]);
    $conversation->messages()->create(['direction' => 'inbound', 'from_email' => $conversation->contact->email, 'subject' => 'Re: hi', 'text_body' => 'Yes please', 'received_at' => now()]);
    $key = outreachKey(['conversations:read']);

    $this->withToken($key)->getJson('/api/v1/conversations?status=interested')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($key)->getJson("/api/v1/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.messages.0.text', 'Yes please');
});
