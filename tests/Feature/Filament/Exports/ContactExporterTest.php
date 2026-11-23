<?php

use App\Enums\ContactStage;
use App\Filament\Exports\ContactExporter;
use App\Models\Contact;
use App\Models\User;

it('neutralises cells that a spreadsheet would run as formulas', function () {
    $contact = Contact::factory()->create([
        'first_name' => '=HYPERLINK("https://evil.test","Click")',
        'company' => '@SUM(1+1)',
        'phone' => '+44 20 7946 0000',
    ]);

    $row = ContactExporter::test(['first_name' => 'First name', 'company' => 'Company', 'phone' => 'Phone'])->export($contact);

    expect($row)->toBe([
        '\'=HYPERLINK("https://evil.test","Click")',
        "'@SUM(1+1)",
        "'+44 20 7946 0000",
    ]);
});

it('exports the prospect details', function () {
    $contact = Contact::factory()->create([
        'job_title' => 'Head of Operations',
        'industry' => 'Logistics',
        'website' => 'https://acme.test',
        'stage' => ContactStage::Interested,
        'owner_id' => User::factory()->create(['name' => 'Priya Shah'])->id,
    ]);

    $row = ContactExporter::test(['job_title' => 'Job title', 'industry' => 'Industry', 'website' => 'Website', 'stage' => 'Stage', 'owner.name' => 'Owner'])->export($contact);

    expect($row)->toBe(['Head of Operations', 'Logistics', 'https://acme.test', 'Interested', 'Priya Shah']);
});
