<?php

use App\Enums\AudienceType;
use App\Enums\ConsentStatus;
use App\Enums\EmailPurpose;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Segment;
use App\Services\Campaigns\CampaignReadiness;

it('skips contacts missing a required variable and reports how many', function () {
    Contact::factory()->consented()->create(['custom_attributes' => ['order_number' => 'A-1']]);
    Contact::factory()->consented()->create(['custom_attributes' => null]);
    $campaign = Campaign::factory()->create(['required_variables' => ['order_number']]);

    $report = app(CampaignReadiness::class)->assess($campaign);

    expect($report->eligibleCount)->toBe(1)
        ->and($report->missingDataCount)->toBe(1)
        ->and($report->canLaunch())->toBeTrue();
});

it('sends to the contacts a segment matches', function () {
    Contact::factory()->consented()->create(['email' => 'ann@acme.test']);
    Contact::factory()->consented()->create(['email' => 'bob@other.test']);
    $segment = Segment::factory()->rules([['type' => 'email_domain', 'data' => ['domain' => 'acme.test']]])->create();
    $campaign = Campaign::factory()->create(['audience_type' => AudienceType::Segment, 'segment_id' => $segment->id]);

    expect($campaign->eligibleAudienceQuery()->pluck('email')->all())->toBe(['ann@acme.test']);
    expect(app(CampaignReadiness::class)->assess($campaign)->eligibleCount)->toBe(1);
});

it('blocks a marketing campaign that uses a transactional template', function () {
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->create(['email_template_id' => EmailTemplate::factory()->transactional()->create(['name' => 'Password reset'])->id]);

    $report = app(CampaignReadiness::class)->assess($campaign);

    expect($report->blockingIssues)->toContain('The template "Password reset" is for Transactional email and cannot be used in a Marketing campaign.');
});

it('asks for a segment when the audience type is segment but none is chosen', function () {
    Contact::factory()->create(['consent_status' => ConsentStatus::Granted]);
    $campaign = Campaign::factory()->create(['audience_type' => AudienceType::Segment]);

    expect(app(CampaignReadiness::class)->assess($campaign)->blockingIssues)->toContain('Choose a segment.');
});

it('warns when a marketing campaign would go out without a postal address', function (EmailPurpose $purpose, ?string $postalAddress, bool $warns) {
    config(['prosend.marketing.postal_address' => $postalAddress]);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->create(['purpose' => $purpose]);

    $warnings = app(CampaignReadiness::class)->assess($campaign)->warnings;

    expect(collect($warnings)->contains(fn (string $warning): bool => str_contains($warning, 'PROSEND_POSTAL_ADDRESS')))->toBe($warns);
})->with([
    'marketing without an address' => [EmailPurpose::Marketing, null, true],
    'marketing with an address' => [EmailPurpose::Marketing, 'Acme Ltd, 1 High Street, London', false],
    'transactional without an address' => [EmailPurpose::Transactional, null, false],
]);
