<?php

use App\Actions\Campaigns\PauseCampaign;
use App\Actions\Campaigns\ResumeCampaign;
use App\Enums\CampaignStatus;
use App\Jobs\PrepareCampaignMessages;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\EmailMessage;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

it('queues the waiting messages again in a new batch after a pause', function () {
    Bus::fake();
    $campaign = Campaign::factory()->prepared()->create();
    $waiting = EmailMessage::factory()->for($campaign)->create();
    EmailMessage::factory()->for($campaign)->accepted()->create();
    app(PauseCampaign::class)->handle($campaign);

    app(ResumeCampaign::class)->handle($campaign->refresh());

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending);
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof SendEmailMessage
        && $batch->jobs->first()->message->is($waiting));
});

it('prepares the campaign again when it was paused before preparation finished', function () {
    Queue::fake([PrepareCampaignMessages::class]);
    $campaign = Campaign::factory()->status(CampaignStatus::Paused)->create();

    app(ResumeCampaign::class)->handle($campaign);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Queued);
    Queue::assertPushed(PrepareCampaignMessages::class);
});
