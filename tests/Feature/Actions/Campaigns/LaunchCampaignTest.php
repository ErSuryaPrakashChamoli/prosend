<?php

use App\Actions\Campaigns\LaunchCampaign;
use App\Enums\CampaignStatus;
use App\Jobs\PrepareCampaignMessages;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\SenderIdentity;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('queues the campaign for preparation and records who launched it', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->create();

    $report = app(LaunchCampaign::class)->handle($campaign);

    expect($report->eligibleCount)->toBe(1);
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Queued);
    Queue::assertPushed(PrepareCampaignMessages::class, fn (PrepareCampaignMessages $job): bool => $job->campaign->is($campaign) && $job->delay === null);
    expect(AuditLog::query()->where('action', 'campaign.launched')->sole()->properties)->toBe([
        'eligible_recipients' => 1,
        'scheduled_at' => null,
    ]);
});

it('schedules the preparation for the campaign send time', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    $this->freezeSecond();
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->create(['scheduled_at' => now()->addDay()]);

    app(LaunchCampaign::class)->handle($campaign);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Scheduled);
    Queue::assertPushed(PrepareCampaignMessages::class, fn (PrepareCampaignMessages $job): bool => $job->delay->equalTo(now()->addDay()));
});

it('refuses to launch from an unverified sender when verification is required', function () {
    config(['prosend.require_verified_senders' => true]);
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->for(SenderIdentity::factory()->unverified()->state(['email' => 'news@acme.test']))->create();

    expect(fn () => app(LaunchCampaign::class)->handle($campaign))
        ->toThrow(ValidationException::class, 'The sender news@acme.test must be verified with the provider before it can send.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('refuses to launch a marketing campaign when no contact has consented', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->create();
    Contact::factory()->consented()->unsubscribed()->create();
    $campaign = Campaign::factory()->create();

    expect(fn () => app(LaunchCampaign::class)->handle($campaign))
        ->toThrow(ValidationException::class, 'None of the contacts in this audience are eligible to receive this email.');

    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('refuses to launch a campaign twice', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->status(CampaignStatus::Sending)->create();

    expect(fn () => app(LaunchCampaign::class)->handle($campaign))
        ->toThrow(ValidationException::class, 'Only draft campaigns can be launched.');

    Queue::assertNotPushed(PrepareCampaignMessages::class);
});
