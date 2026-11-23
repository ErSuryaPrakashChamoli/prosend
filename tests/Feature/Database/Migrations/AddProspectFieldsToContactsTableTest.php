<?php

use App\Enums\ContactStage;
use App\Enums\MessageSource;
use App\Models\Contact;
use App\Models\EmailMessage;

it('starts contacts already emailed from a campaign or Compose as contacted, at their latest acceptance', function () {
    $migration = require database_path('migrations/2026_10_29_074623_add_prospect_fields_to_contacts_table.php');
    $emailed = Contact::factory()->create();
    $apiOnly = Contact::factory()->create();
    $neverAccepted = Contact::factory()->create();
    EmailMessage::factory()->for($emailed)->accepted()->create(['source' => MessageSource::Campaign, 'accepted_at' => '2026-10-20 08:00:00']);
    EmailMessage::factory()->for($emailed)->accepted()->create(['source' => MessageSource::Compose, 'accepted_at' => '2026-10-25 09:00:00']);
    EmailMessage::factory()->for($apiOnly)->accepted()->create(['source' => MessageSource::Api, 'accepted_at' => '2026-10-25 09:00:00']);
    EmailMessage::factory()->for($neverAccepted)->create(['source' => MessageSource::Campaign]);

    $migration->markEmailedContactsAsContacted();

    expect($emailed->refresh())
        ->stage->toBe(ContactStage::Contacted)
        ->last_contacted_at->toDateTimeString()->toBe('2026-10-25 09:00:00');
    expect($apiOnly->refresh())
        ->stage->toBe(ContactStage::New)
        ->last_contacted_at->toBeNull();
    expect($neverAccepted->refresh())
        ->stage->toBe(ContactStage::New)
        ->last_contacted_at->toBeNull();
});
