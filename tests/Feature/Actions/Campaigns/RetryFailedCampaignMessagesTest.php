<?php

use App\Actions\Campaigns\RetryFailedCampaignMessages;
use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Models\Campaign;
use App\Models\EmailMessage;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

it('retries temporary failures but not rejected addresses or unknown outcomes', function () {
    Bus::fake();
    $campaign = Campaign::factory()->status(CampaignStatus::PartiallyFailed)->create();
    $temporary = EmailMessage::factory()->for($campaign)->status(MessageStatus::Failed)->create(['is_retryable' => true]);
    $rejected = EmailMessage::factory()->for($campaign)->status(MessageStatus::Failed)->create(['is_retryable' => false]);
    $unknown = EmailMessage::factory()->for($campaign)->status(MessageStatus::Unknown)->create();

    $retried = app(RetryFailedCampaignMessages::class)->handle($campaign);

    expect($retried)->toBe(1);
    expect($temporary->refresh()->status)->toBe(MessageStatus::Queued);
    expect($rejected->refresh()->status)->toBe(MessageStatus::Failed);
    expect($unknown->refresh()->status)->toBe(MessageStatus::Unknown);
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending);
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1);
});

it('explains when nothing can be retried', function () {
    Bus::fake();
    $campaign = Campaign::factory()->status(CampaignStatus::Failed)->create();
    EmailMessage::factory()->for($campaign)->status(MessageStatus::Failed)->create(['is_retryable' => false]);

    expect(fn () => app(RetryFailedCampaignMessages::class)->handle($campaign))
        ->toThrow(ValidationException::class, 'There are no failures that can be retried.');

    Bus::assertNothingBatched();
});
