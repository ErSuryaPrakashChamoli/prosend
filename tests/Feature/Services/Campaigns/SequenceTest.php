<?php

use App\Actions\Campaigns\CancelCampaign;
use App\Enums\CampaignStatus;
use App\Enums\ContactStage;
use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Enums\MessageStatus;
use App\Enums\SequenceStatus;
use App\Enums\StepCondition;
use App\Enums\SuppressionReason;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\PrepareCampaignMessages;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignStep;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\Suppression;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignApprovalNotifier;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\SequenceRunner;
use App\Services\Sending\EmailContentRenderer;
use App\Services\Sending\EmailSender;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMailProviderDriver;

/**
 * A queued campaign with two follow-ups (2 and 3 days apart), no sending window, prepared for one consented contact.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Campaign, 1: Contact, 2: EmailMessage}
 */
function launchedSequence(array $attributes = [], array $steps = [[], []]): array
{
    Bus::fake([SendEmailMessage::class]);
    $contact = Contact::factory()->consented()->create(['first_name' => 'Jane', 'email' => 'jane@example.com']);
    $campaign = Campaign::factory()->status(CampaignStatus::Queued)->create(['subject' => 'Quick question', ...$attributes]);

    foreach ($steps as $index => $step) {
        CampaignStep::factory()->for($campaign)->create(['position' => $index + 1, 'wait_days' => $index + 2, ...$step]);
    }

    (new PrepareCampaignMessages($campaign))->handle(app(CampaignDispatcher::class), app(CampaignApprovalNotifier::class), app(AuditLogger::class));

    return [$campaign->refresh(), $contact, EmailMessage::query()->where('campaign_id', $campaign->id)->sole()];
}

function sendNow(EmailMessage $message): void
{
    app()->call([new SendEmailMessage($message), 'handle']);
}

function prospectOf(Campaign $campaign): CampaignProspect
{
    return CampaignProspect::query()->where('campaign_id', $campaign->id)->sole();
}

function runSequences(): int
{
    return app(SequenceRunner::class)->run();
}

it('enrols every recipient in the sequence when the campaign is prepared', function () {
    [$campaign, $contact, $first] = launchedSequence();

    expect(prospectOf($campaign))
        ->contact_id->toBe($contact->id)
        ->status->toBe(SequenceStatus::Active)
        ->current_step->toBe(0)
        ->next_step_due_at->toBeNull();
    expect($first->step_number)->toBe(1)
        ->and($campaign->status)->toBe(CampaignStatus::Sending);
});

it('schedules the first follow-up after the first email is accepted, and keeps the campaign active meanwhile', function () {
    FakeMailProviderDriver::install();
    $this->freezeSecond();
    [$campaign, , $first] = launchedSequence();

    sendNow($first);

    expect(prospectOf($campaign))
        ->current_step->toBe(1)
        ->next_step_due_at->toDateTimeString()->toBe(now()->addDays(2)->toDateTimeString())
        ->last_sent_at->not->toBeNull();
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending);
});

it('sends the follow-up when it is due, in the same thread, exactly once', function () {
    $driver = FakeMailProviderDriver::install();
    [$campaign, , $first] = launchedSequence();
    sendNow($first);

    $this->travel(2)->days();
    expect(runSequences())->toBe(1)
        ->and(runSequences())->toBe(0);

    $followUp = EmailMessage::query()->where('campaign_id', $campaign->id)->where('step_number', 2)->sole();
    expect($followUp)->subject->toBe('Re: Quick question')->status->toBe(MessageStatus::Queued);
    Bus::assertDispatched(SendEmailMessage::class, fn (SendEmailMessage $job): bool => $job->message->is($followUp));

    sendNow($followUp);

    $email = $driver->sent[1]->getOriginalMessage();
    expect($email->getSubject())->toBe('Re: Quick question')
        ->and($email->getHeaders()->get('In-Reply-To')->getBodyAsString())->toBe("<{$first->refresh()->provider_message_id}>")
        ->and($email->getHtmlBody())->toContain('Just following up, Jane.');
    expect(prospectOf($campaign))->current_step->toBe(2)->next_step_due_at->not->toBeNull();
});

it('finishes the sequence after the last step and then completes the campaign', function () {
    FakeMailProviderDriver::install();
    [$campaign, , $first] = launchedSequence(steps: [[]]);
    sendNow($first);

    $this->travel(2)->days();
    runSequences();
    sendNow(EmailMessage::query()->where('step_number', 2)->sole());

    expect(prospectOf($campaign))->status->toBe(SequenceStatus::Completed)->next_step_due_at->toBeNull();
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Completed);
});

it('stops the sequence when the prospect replies, and cancels a follow-up that was already queued', function () {
    FakeMailProviderDriver::install();
    [$campaign, $contact, $first] = launchedSequence();
    sendNow($first);
    $this->travel(2)->days();
    runSequences();
    $queued = EmailMessage::query()->where('step_number', 2)->sole();

    $contact->update(['last_replied_at' => now()]);
    sendNow($queued);

    expect(prospectOf($campaign))->status->toBe(SequenceStatus::Replied)->finish_reason->toBe('Replied');
    expect($queued->refresh())->status->toBe(MessageStatus::Cancelled)->error_message->toContain('Replied');
    expect(runSequences())->toBe(0);
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Completed);
});

it('stops the sequence when the address is suppressed, with a status matching the reason', function (SuppressionReason $reason, SequenceStatus $status) {
    FakeMailProviderDriver::install();
    [$campaign, $contact, $first] = launchedSequence();
    sendNow($first);

    Suppression::record($contact->email, $reason, 'test');

    expect(prospectOf($campaign)->status)->toBe($status);
})->with([
    'unsubscribe' => [SuppressionReason::Unsubscribed, SequenceStatus::Unsubscribed],
    'hard bounce' => [SuppressionReason::HardBounce, SequenceStatus::Bounced],
    'complaint' => [SuppressionReason::Complaint, SequenceStatus::Unsubscribed],
    'manual' => [SuppressionReason::Manual, SequenceStatus::Stopped],
]);

it('skips a step whose condition is not met and moves on to the next', function () {
    FakeMailProviderDriver::install();
    [$campaign, , $first] = launchedSequence(steps: [['condition' => StepCondition::Opened], []]);
    sendNow($first);

    $this->travel(2)->days();
    expect(runSequences())->toBe(0);

    expect(prospectOf($campaign))
        ->current_step->toBe(2)
        ->status->toBe(SequenceStatus::Active)
        ->next_step_due_at->not->toBeNull();
    expect(prospectOf($campaign)->events()->where('type', 'skipped')->exists())->toBeTrue();
});

it('sends an "if not opened" step only when no earlier email was opened', function () {
    FakeMailProviderDriver::install();
    [$campaign, , $first] = launchedSequence(steps: [['condition' => StepCondition::NotOpened]]);
    sendNow($first);
    $first->refresh()->recordEvent(EmailEventType::Opened, EventSource::Tracking);

    $this->travel(2)->days();

    expect(runSequences())->toBe(0)
        ->and(prospectOf($campaign)->status)->toBe(SequenceStatus::Completed);
});

it('holds an email outside the sending window until it opens, in the prospect\'s own time zone when asked', function () {
    Queue::fake();
    $this->travelTo(now('UTC')->next('Saturday')->setTime(10, 0));
    FakeMailProviderDriver::install();
    $contact = Contact::factory()->consented()->create(['timezone' => 'Asia/Kolkata']);
    $campaign = Campaign::factory()->prepared()->create([
        'send_days' => [1, 2, 3, 4, 5],
        'send_window_start' => '09:00',
        'send_window_end' => '17:00',
        'timezone' => 'UTC',
        'use_prospect_timezone' => true,
    ]);
    $message = EmailMessage::factory()->for($campaign)->for($contact)->create(['to_email' => $contact->email]);

    sendNow($message);

    expect($message->refresh())->status->toBe(MessageStatus::Deferred)->error_message->toContain('Asia/Kolkata');
    // Monday 09:00 in Kolkata is 03:30 UTC.
    Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job): bool => $job->delay->format('D H:i') === now()->next('Monday')->setTime(3, 30)->addSeconds($message->id % 300)->format('D H:i'));
});

it('plans the first emails inside the sending window', function () {
    $this->travelTo(now('UTC')->next('Saturday')->setTime(10, 0));
    [, , $first] = launchedSequence(['send_days' => [1, 2, 3, 4, 5], 'send_window_start' => '09:00', 'send_window_end' => '17:00', 'timezone' => 'UTC']);

    Bus::assertBatched(fn ($batch): bool => $batch->jobs->first()->delay->toDateTimeString() === now()->next('Monday')->setTime(9, 0)->toDateTimeString());
    expect($first->status)->toBe(MessageStatus::Queued);
});

it('stops every sequence when the campaign is stopped', function () {
    FakeMailProviderDriver::install();
    [$campaign, , $first] = launchedSequence();
    sendNow($first);

    app(CancelCampaign::class)->handle($campaign);

    expect(prospectOf($campaign))->status->toBe(SequenceStatus::Stopped)->next_step_due_at->toBeNull();
    $this->travel(5)->days();
    expect(runSequences())->toBe(0);
});

it('ends the sequence when a step is rejected, and continues it when the email is retried', function () {
    [$campaign, , $first] = launchedSequence();

    $first->markFailed('Rejected', '550');
    expect(prospectOf($campaign)->status)->toBe(SequenceStatus::Failed);

    $first->resetForRetry();
    expect(prospectOf($campaign)->status)->toBe(SequenceStatus::Active);
});

it('does not follow up after a step whose delivery is uncertain', function () {
    [$campaign, , $first] = launchedSequence();

    $first->markAmbiguous('Connection dropped');

    expect(prospectOf($campaign))->status->toBe(SequenceStatus::Failed)->finish_reason->toContain('uncertain');
});

it('gives a follow-up with its own subject no threading headers', function () {
    $campaign = Campaign::factory()->create();
    $step = CampaignStep::factory()->for($campaign)->create(['subject' => 'A different idea']);
    $message = EmailMessage::factory()->for($campaign)->create(['campaign_step_id' => $step->id, 'step_number' => 2]);

    expect(app(EmailSender::class)->threadingHeaders($message))->toBe([]);
});

it('completes the campaign when the last prospect waiting for a follow-up replies', function () {
    FakeMailProviderDriver::install();
    [$campaign, $contact, $first] = launchedSequence();
    sendNow($first);
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending);

    $contact->update(['last_replied_at' => now()]);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Completed);
});

it('ends sequences when a prospect is moved to a replied stage, one by one or in bulk', function () {
    FakeMailProviderDriver::install();
    [$campaign, $contact, $first] = launchedSequence();
    sendNow($first);

    $contact->update(['stage' => ContactStage::Interested]);

    expect(prospectOf($campaign)->status)->toBe(SequenceStatus::Replied);

    $other = Contact::factory()->consented()->create();
    $waiting = CampaignProspect::query()->create(['campaign_id' => $campaign->id, 'contact_id' => $other->id, 'current_step' => 1, 'next_step_due_at' => now()->addDay()]);
    $this->actingAs(User::factory()->sender()->create());
    Livewire\Livewire::test(ListContacts::class)
        ->callTableBulkAction('setStage', [$other], data: ['stage' => ContactStage::Replied->value]);

    expect($waiting->refresh()->status)->toBe(SequenceStatus::Replied);
});

it('refuses a follow-up at send time when a reply was recorded without ending the sequence', function () {
    FakeMailProviderDriver::install();
    [$campaign, $contact, $first] = launchedSequence();
    sendNow($first);
    $this->travel(2)->days();
    runSequences();
    $followUp = EmailMessage::query()->where('step_number', 2)->sole();

    // As if the reply was saved without model events.
    Contact::query()->whereKey($contact->id)->update(['last_replied_at' => now()]);
    sendNow($followUp);

    expect($followUp->refresh()->status)->toBe(MessageStatus::Cancelled)
        ->and(prospectOf($campaign)->status)->toBe(SequenceStatus::Replied);
});

it('never adds tracking to previews', function () {
    $campaign = Campaign::factory()->create(['track_opens' => true, 'track_clicks' => true, 'body' => '<p><a href="https://acme.test">Site</a></p>']);
    $message = EmailMessage::factory()->for($campaign)->create();

    expect(app(EmailContentRenderer::class)->render($message, track: false)->html)
        ->not->toContain('/t/o/')
        ->toContain('href="https://acme.test"');
});

it('keeps a follow-up held by the daily limit near the front of the line, however long after the first emails it was created', function () {
    Queue::fake();
    $this->freezeSecond();
    FakeMailProviderDriver::install();
    $sender = SenderIdentity::factory()->for(SmtpProvider::factory()->dailyLimit(0))->create();
    $campaign = Campaign::factory()->prepared()->create(['sender_identity_id' => $sender->id]);
    EmailMessage::factory()->for($campaign)->for($sender)->accepted()->create();
    EmailMessage::factory()->count(30)->create();
    $followUp = EmailMessage::factory()->for($campaign)->for($sender)->create(['step_number' => 2]);

    sendNow($followUp);

    Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job): bool => $job->delay->equalTo(now()->addDay()->startOfDay()->addMinute()));
});

it('reopens a campaign that was closed while a follow-up was still waiting', function () {
    $campaign = Campaign::factory()->status(CampaignStatus::Completed)->create(['prepared_at' => now(), 'completed_at' => now()]);
    CampaignStep::factory()->for($campaign)->create();
    CampaignProspect::query()->create(['campaign_id' => $campaign->id, 'contact_id' => Contact::factory()->create()->id, 'current_step' => 1, 'next_step_due_at' => now()->addDay()]);

    runSequences();

    expect($campaign->refresh())->status->toBe(CampaignStatus::Sending)->completed_at->toBeNull();
});

it('holds follow-ups while the client is deactivated', function () {
    FakeMailProviderDriver::install();
    [$campaign, , $first] = launchedSequence();
    sendNow($first);
    $campaign->client->deactivate();

    $this->travel(2)->days();

    expect(runSequences())->toBe(0);

    $campaign->client->activate();

    expect(runSequences())->toBe(1);
});

it('refuses to roll back sequences once follow-ups exist', function () {
    EmailMessage::factory()->for(Campaign::factory())->create(['step_number' => 2]);
    $migration = require database_path('migrations/2026_11_23_071148_add_sequences_to_campaigns.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Follow-up emails exist');
});
