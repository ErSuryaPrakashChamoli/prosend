<?php

use App\Actions\Campaigns\CancelCampaign;
use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Models\Campaign;
use App\Models\EmailMessage;
use Illuminate\Validation\ValidationException;

it('cancels the unsent messages and keeps the sent ones', function () {
    $campaign = Campaign::factory()->prepared()->create();
    $queued = EmailMessage::factory()->for($campaign)->create();
    $deferred = EmailMessage::factory()->for($campaign)->status(MessageStatus::Deferred)->create();
    $accepted = EmailMessage::factory()->for($campaign)->accepted()->create();

    $cancelled = app(CancelCampaign::class)->handle($campaign);

    expect($cancelled)->toBe(2);
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Cancelled);
    expect($queued->refresh()->status)->toBe(MessageStatus::Cancelled);
    expect($deferred->refresh()->status)->toBe(MessageStatus::Cancelled);
    expect($accepted->refresh()->status)->toBe(MessageStatus::Accepted);
});

it('refuses to cancel a finished campaign', function () {
    $campaign = Campaign::factory()->status(CampaignStatus::Completed)->create();

    expect(fn () => app(CancelCampaign::class)->handle($campaign))
        ->toThrow(ValidationException::class, 'This campaign can no longer be cancelled.');
});
