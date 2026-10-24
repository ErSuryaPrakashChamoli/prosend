<?php

use App\Enums\AudienceType;
use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Enums\SuppressionReason;
use App\Jobs\PrepareCampaignMessages;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\EmailMessage;
use App\Models\Suppression;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;

it('creates one message per eligible contact and spaces the sends at the campaign rate', function () {
    Bus::fake();
    $this->freezeSecond();
    $list = ContactList::factory()->create();
    $otherList = ContactList::factory()->create();
    $eligible = Contact::factory()->consented()->count(2)->create();
    $list->contacts()->attach($eligible);
    $otherList->contacts()->attach($eligible->first());
    $list->contacts()->attach(Contact::factory()->create());
    $list->contacts()->attach(Contact::factory()->consented()->unsubscribed()->create());
    $suppressed = Contact::factory()->consented()->create();
    Suppression::factory()->create(['email' => $suppressed->email, 'reason' => SuppressionReason::Complaint]);
    $list->contacts()->attach($suppressed);
    $campaign = Campaign::factory()->status(CampaignStatus::Queued)->create([
        'audience_type' => AudienceType::Lists,
        'send_rate_per_minute' => 30,
    ]);
    $campaign->lists()->attach([$list->id, $otherList->id]);

    app()->call([new PrepareCampaignMessages($campaign), 'handle']);

    expect($campaign->refresh())
        ->status->toBe(CampaignStatus::Sending)
        ->started_at->not->toBeNull()
        ->batch_id->not->toBeNull();
    expect(EmailMessage::query()->pluck('to_email')->sort()->values()->all())->toBe($eligible->pluck('email')->sort()->values()->all());
    expect(EmailMessage::query()->pluck('status')->unique()->all())->toBe([MessageStatus::Queued]);
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job): bool => $job instanceof SendEmailMessage)
        && $batch->jobs->map(fn (SendEmailMessage $job): int => (int) now()->diffInSeconds($job->delay))->all() === [0, 2]);
});

it('does nothing when the campaign was cancelled before it started', function () {
    Bus::fake();
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->status(CampaignStatus::Cancelled)->create();

    app()->call([new PrepareCampaignMessages($campaign), 'handle']);

    expect(EmailMessage::query()->count())->toBe(0);
    Bus::assertNothingBatched();
});

it('completes straight away when no contact is eligible any more', function () {
    Bus::fake();
    $campaign = Campaign::factory()->status(CampaignStatus::Queued)->create();

    app()->call([new PrepareCampaignMessages($campaign), 'handle']);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Completed);
    Bus::assertNothingBatched();
});

it('continues preparing after an attempt that crashed part-way', function () {
    Bus::fake();
    $contacts = Contact::factory()->consented()->count(3)->create();
    $campaign = Campaign::factory()->status(CampaignStatus::Sending)->create(['started_at' => now()]);
    EmailMessage::factory()->for($campaign)->for($contacts->first())->create(['to_email' => $contacts->first()->email]);

    app()->call([new PrepareCampaignMessages($campaign), 'handle']);

    expect($campaign->refresh()->prepared_at)->not->toBeNull();
    expect($campaign->messages()->pluck('contact_id')->sort()->values()->all())->toBe($contacts->pluck('id')->sort()->values()->all());
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 3);
});

it('does not prepare a campaign twice', function () {
    Bus::fake();
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->prepared()->create();

    app()->call([new PrepareCampaignMessages($campaign), 'handle']);

    expect(EmailMessage::query()->count())->toBe(0);
    Bus::assertNothingBatched();
});
