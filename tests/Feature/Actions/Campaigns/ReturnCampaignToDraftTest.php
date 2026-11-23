<?php

use App\Actions\Campaigns\ReturnCampaignToDraft;
use App\Enums\CampaignStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('sends a campaign back to draft with the approver\'s reason and tells the submitter', function () {
    $submitter = User::factory()->sender()->create();
    $campaign = Campaign::factory()->pendingApproval()->create(['name' => 'Autumn news', 'submitted_by' => $submitter->id]);

    app(ReturnCampaignToDraft::class)->handle($campaign, User::factory()->admin()->create(['name' => 'Priya']), ' Wrong list ');

    expect($campaign->refresh())
        ->status->toBe(CampaignStatus::Draft)
        ->submitted_at->toBeNull()
        ->review_note->toBe('Wrong list');
    expect(AuditLog::query()->where('action', 'campaign.returned_to_draft')->sole()->properties)->toBe(['note' => 'Wrong list']);
    expect($submitter->notifications()->sole()->data)
        ->title->toBe('Campaign returned to draft: Autumn news')
        ->body->toBe('Priya returned it to draft: Wrong list');
});

it('lets the submitter withdraw their own campaign', function () {
    $submitter = User::factory()->sender()->create();
    $campaign = Campaign::factory()->pendingApproval()->create(['submitted_by' => $submitter->id]);

    app(ReturnCampaignToDraft::class)->handle($campaign, $submitter);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft)
        ->and($submitter->notifications()->count())->toBe(0);
});

it('refuses a sender who did not submit the campaign', function () {
    $campaign = Campaign::factory()->pendingApproval()->create(['submitted_by' => User::factory()->sender()->create()->id]);

    expect(fn () => app(ReturnCampaignToDraft::class)->handle($campaign, User::factory()->sender()->create()))
        ->toThrow(ValidationException::class, 'Only an administrator or the person who submitted it can return this campaign to draft.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingApproval);
});

it('refuses a campaign that is no longer waiting for approval', function () {
    $campaign = Campaign::factory()->prepared()->create();

    expect(fn () => app(ReturnCampaignToDraft::class)->handle($campaign, User::factory()->admin()->create()))
        ->toThrow(ValidationException::class, 'This campaign is no longer waiting for approval.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending);
});

it('never returns a campaign that has started sending to draft', function () {
    $campaign = Campaign::factory()->pendingApproval()->create(['started_at' => now()]);

    expect(fn () => app(ReturnCampaignToDraft::class)->handle($campaign, User::factory()->admin()->create()))
        ->toThrow(ValidationException::class, 'This campaign is no longer waiting for approval.');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingApproval);
});
