<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Services\Campaigns\CampaignReadiness;

function campaignSendingThrough(SmtpProvider $provider): Campaign
{
    Contact::factory()->consented()->count(2)->create();

    return Campaign::factory()->create([
        'sender_identity_id' => SenderIdentity::factory()->for($provider)->create(['email' => 'news@acme.test'])->id,
    ]);
}

it('warns before launch that a sandbox campaign is captured and reaches nobody', function () {
    $campaign = campaignSendingThrough(SmtpProvider::factory()->mailtrapSandbox()->create(['name' => 'QA sandbox']));

    $report = app(CampaignReadiness::class)->assess($campaign);

    expect($report->canLaunch())->toBeTrue()
        ->and($report->warnings)->toContain('news@acme.test sends through the sandbox provider QA sandbox. All 2 emails will be captured there for inspection and none will reach the recipients.');
});

it('blocks a sandbox campaign where sandbox providers are turned off', function () {
    config(['prosend.providers.allow_sandbox' => false]);
    $campaign = campaignSendingThrough(SmtpProvider::factory()->mailtrapSandbox()->create(['name' => 'QA sandbox']));

    $report = app(CampaignReadiness::class)->assess($campaign);

    expect($report->canLaunch())->toBeFalse()
        ->and($report->blockingIssues)->toContain('The provider QA sandbox is a sandbox, and sandbox providers are turned off in this environment (PROSEND_ALLOW_SANDBOX_PROVIDERS).');
});

it('warns when Mailtrap marketing email would use the transactional stream', function () {
    $transactional = campaignSendingThrough(SmtpProvider::factory()->mailtrap()->create(['name' => 'Mailtrap transactional']));

    expect(app(CampaignReadiness::class)->assess($transactional)->warnings)
        ->toContain('Mailtrap requires marketing email to go through its Bulk Stream (bulk.smtp.mailtrap.io), but Mailtrap transactional uses live.smtp.mailtrap.io. Use a sender identity on a Mailtrap Bulk Stream provider.');

    $bulk = Campaign::factory()->create([
        'sender_identity_id' => SenderIdentity::factory()->for(SmtpProvider::factory()->mailtrap()->create(['host' => 'bulk.smtp.mailtrap.io']))->create()->id,
    ]);

    expect(implode(' ', app(CampaignReadiness::class)->assess($bulk)->warnings))->not->toContain('Bulk Stream');
});
