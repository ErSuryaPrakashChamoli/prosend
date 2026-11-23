<?php

use App\Actions\Campaigns\LaunchCampaign;
use App\Enums\CampaignStatus;
use App\Jobs\PrepareCampaignMessages;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\SenderIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('queues the campaign for preparation and records who approved it', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    $admin = User::factory()->admin()->create();
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->pendingApproval()->create();

    $report = app(LaunchCampaign::class)->handle($campaign, $admin);

    expect($report->eligibleCount)->toBe(1);
    expect($campaign->refresh())
        ->status->toBe(CampaignStatus::Queued)
        ->approved_by->toBe($admin->id)
        ->approved_at->not->toBeNull()
        ->approved_recipient_count->toBe(1);
    Queue::assertPushed(PrepareCampaignMessages::class, fn (PrepareCampaignMessages $job): bool => $job->campaign->is($campaign) && $job->delay === null);
    $log = AuditLog::query()->where('action', 'campaign.launched')->sole();
    expect($log->user_id)->toBe($admin->id)
        ->and($log->properties)->toBe([
            'eligible_recipients' => 1,
            'scheduled_at' => null,
        ]);
});

it('schedules the preparation for the campaign send time', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    $this->freezeSecond();
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->pendingApproval()->create(['scheduled_at' => now()->addDay()]);

    app(LaunchCampaign::class)->handle($campaign, User::factory()->admin()->create());

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Scheduled);
    Queue::assertPushed(PrepareCampaignMessages::class, fn (PrepareCampaignMessages $job): bool => $job->delay->equalTo(now()->addDay()));
});

it('refuses to launch from an unverified sender when verification is required', function () {
    config(['prosend.require_verified_senders' => true]);
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->pendingApproval()->for(SenderIdentity::factory()->unverified()->state(['email' => 'news@acme.test']))->create();

    expect(fn () => app(LaunchCampaign::class)->handle($campaign, User::factory()->admin()->create()))
        ->toThrow(ValidationException::class, 'The sender news@acme.test must be verified with the provider before it can send.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingApproval);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('refuses to launch a marketing campaign when no contact has consented', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->create();
    Contact::factory()->consented()->unsubscribed()->create();
    $campaign = Campaign::factory()->pendingApproval()->create();

    expect(fn () => app(LaunchCampaign::class)->handle($campaign, User::factory()->admin()->create()))
        ->toThrow(ValidationException::class, 'None of the contacts in this audience are eligible to receive this email.');

    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('refuses to launch a campaign twice', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->status(CampaignStatus::Sending)->create();

    expect(fn () => app(LaunchCampaign::class)->handle($campaign, User::factory()->admin()->create()))
        ->toThrow(ValidationException::class, 'Only campaigns waiting for approval can be launched.');

    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('refuses to launch a draft that was never submitted', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->create();

    expect(fn () => app(LaunchCampaign::class)->handle($campaign, User::factory()->admin()->create()))
        ->toThrow(ValidationException::class, 'Only campaigns waiting for approval can be launched.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('refuses approval by someone who is not an administrator', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->pendingApproval()->create();

    expect(fn () => app(LaunchCampaign::class)->handle($campaign, User::factory()->sender()->create()))
        ->toThrow(ValidationException::class, 'Only an administrator can approve this campaign.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingApproval);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('requires a different administrator when a second approver is required', function (string $ownRole) {
    config(['prosend.campaigns.approval.require_second_approver' => true]);
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $admin = User::factory()->admin()->create();
    $campaign = Campaign::factory()->pendingApproval()->create([$ownRole => $admin->id]);

    expect(fn () => app(LaunchCampaign::class)->handle($campaign, $admin))
        ->toThrow(ValidationException::class, 'An administrator who did not create or submit this campaign must approve it.');

    app(LaunchCampaign::class)->handle($campaign->refresh(), User::factory()->admin()->create());

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Queued);
    Queue::assertPushed(PrepareCampaignMessages::class, 1);
})->with(['created_by', 'updated_by', 'submitted_by']);

it('lets an administrator approve their own campaign when no second approver is required', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $admin = User::factory()->admin()->create();
    $campaign = Campaign::factory()->pendingApproval()->create(['created_by' => $admin->id, 'submitted_by' => $admin->id]);

    app(LaunchCampaign::class)->handle($campaign, $admin);

    expect($campaign->refresh()->approved_by)->toBe($admin->id);
});

it('tells the submitter that the campaign was approved', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $submitter = User::factory()->sender()->create();
    $campaign = Campaign::factory()->pendingApproval()->create(['name' => 'Autumn news', 'submitted_by' => $submitter->id]);

    app(LaunchCampaign::class)->handle($campaign, User::factory()->admin()->create(['name' => 'Priya']));

    expect($submitter->notifications()->sole()->data)
        ->title->toBe('Campaign approved: Autumn news')
        ->body->toBe('Priya approved it for 1 recipient.');
});

it('refuses an approval when the campaign changed after it was reviewed', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->pendingApproval()->create(['subject' => 'Reviewed subject']);
    $reviewedFingerprint = $campaign->submissionFingerprint();
    $campaign->forceFill(['subject' => 'Changed subject'])->save();

    expect(fn () => app(LaunchCampaign::class)->handle($campaign, User::factory()->admin()->create(), $reviewedFingerprint, 1))
        ->toThrow(ValidationException::class, 'The campaign changed after you opened this review. Review it again before approving.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingApproval);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('approves the recipient count that was reviewed', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    config(['prosend.campaigns.approval.audience_growth_margin_percent' => 10]);
    Contact::factory()->consented()->count(11)->create();
    $campaign = Campaign::factory()->pendingApproval()->create();

    app(LaunchCampaign::class)->handle($campaign, User::factory()->admin()->create(), $campaign->submissionFingerprint(), 10);

    expect($campaign->refresh()->approved_recipient_count)->toBe(10);
});

it('refuses an approval when the audience grew beyond the margin after it was reviewed', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    config(['prosend.campaigns.approval.audience_growth_margin_percent' => 10]);
    Contact::factory()->consented()->count(12)->create();
    $campaign = Campaign::factory()->pendingApproval()->create();

    expect(fn () => app(LaunchCampaign::class)->handle($campaign, User::factory()->admin()->create(), $campaign->submissionFingerprint(), 10))
        ->toThrow(ValidationException::class, 'The audience grew from 10 to 12 eligible recipients since you opened this review. Review it again before approving.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingApproval);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});
