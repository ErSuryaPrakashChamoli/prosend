<?php

use App\Actions\Campaigns\SubmitCampaignForApproval;
use App\Enums\CampaignStatus;
use App\Jobs\PrepareCampaignMessages;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('locks a ready draft for approval and notifies the administrators', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    $this->freezeSecond();
    $submitter = User::factory()->sender()->create(['name' => 'Sam']);
    $admin = User::factory()->admin()->create();
    Contact::factory()->consented()->count(2)->create();
    $campaign = Campaign::factory()->create(['name' => 'Autumn news', 'review_note' => 'Fix the subject']);

    $report = app(SubmitCampaignForApproval::class)->handle($campaign, $submitter);

    expect($report->eligibleCount)->toBe(2);
    expect($campaign->refresh())
        ->status->toBe(CampaignStatus::PendingApproval)
        ->submitted_by->toBe($submitter->id)
        ->submitted_at->toDateTimeString()->toBe(now()->toDateTimeString())
        ->review_note->toBeNull();
    expect(AuditLog::query()->where('action', 'campaign.submitted')->sole()->properties)->toBe(['eligible_recipients' => 2]);
    expect($admin->notifications()->sole()->data)
        ->title->toBe('Campaign waiting for approval: Autumn news')
        ->body->toBe('Sam submitted it to send to 2 recipients.');
    expect($submitter->notifications()->count())->toBe(0);
    Queue::assertNotPushed(PrepareCampaignMessages::class);
});

it('does not notify an administrator who may not approve the campaign', function () {
    config(['prosend.campaigns.approval.require_second_approver' => true]);
    $creator = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->create(['created_by' => $creator->id]);

    app(SubmitCampaignForApproval::class)->handle($campaign, User::factory()->sender()->create());

    expect($creator->notifications()->count())->toBe(0)
        ->and($otherAdmin->notifications()->count())->toBe(1);
});

it('refuses to submit a draft with blocking issues', function () {
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->create(['subject' => null]);

    expect(fn () => app(SubmitCampaignForApproval::class)->handle($campaign, User::factory()->sender()->create()))
        ->toThrow(ValidationException::class, 'Add a subject.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft);
});

it('refuses a submission by a viewer or of a campaign that is not a draft', function (CampaignStatus $status, string $role) {
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->status($status)->create();

    expect(fn () => app(SubmitCampaignForApproval::class)->handle($campaign, User::factory()->create(['role' => $role])))
        ->toThrow(ValidationException::class, 'Only draft campaigns can be submitted, by someone who can send email.');

    expect($campaign->refresh()->status)->toBe($status);
})->with([
    'viewer' => [CampaignStatus::Draft, 'viewer'],
    'already waiting' => [CampaignStatus::PendingApproval, 'sender'],
    'already sending' => [CampaignStatus::Sending, 'sender'],
]);
