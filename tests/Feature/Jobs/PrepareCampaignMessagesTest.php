<?php

use App\Enums\AudienceType;
use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Enums\SuppressionReason;
use App\Jobs\PrepareCampaignMessages;
use App\Jobs\SendEmailMessage;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\EmailMessage;
use App\Models\Suppression;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

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

it('returns the campaign for approval when the audience grew beyond the approved size', function () {
    Bus::fake();
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $submitter = User::factory()->sender()->create();
    Contact::factory()->consented()->count(12)->create();
    $campaign = Campaign::factory()->status(CampaignStatus::Queued)->create([
        'name' => 'Autumn news',
        'submitted_by' => $submitter->id,
        'approved_by' => $admin->id,
        'approved_at' => now(),
        'approved_recipient_count' => 10,
    ]);

    app()->call([new PrepareCampaignMessages($campaign), 'handle']);

    expect($campaign->refresh())
        ->status->toBe(CampaignStatus::PendingApproval)
        ->approved_by->toBeNull()
        ->approved_recipient_count->toBeNull()
        ->started_at->toBeNull()
        ->review_note->toBe('Returned for approval when sending was due: 12 recipients are eligible now, but 10 were approved.');
    expect($campaign->messages()->count())->toBe(0);
    Bus::assertNothingBatched();
    expect(AuditLog::query()->where('action', 'campaign.returned_for_approval')->sole()->properties)->toBe([
        'approved_recipients' => 10,
        'eligible_recipients' => 12,
    ]);
    Notification::assertSentTo([$admin, $submitter], DatabaseNotification::class, fn (DatabaseNotification $notification): bool => $notification->data['title'] === 'Campaign needs approval again: Autumn news');
});

it('sends when the audience grew within the approval margin', function () {
    Bus::fake();
    config(['prosend.campaigns.approval.audience_growth_margin_percent' => 10]);
    Contact::factory()->consented()->count(11)->create();
    $campaign = Campaign::factory()->status(CampaignStatus::Queued)->create(['approved_recipient_count' => 10]);

    app()->call([new PrepareCampaignMessages($campaign), 'handle']);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending)
        ->and($campaign->messages()->count())->toBe(11);
});

it('keeps preparing a campaign that was resumed part-way through preparation, even if its audience grew', function () {
    Bus::fake();
    Contact::factory()->consented()->count(12)->create();
    $campaign = Campaign::factory()->status(CampaignStatus::Queued)->create(['approved_recipient_count' => 10, 'started_at' => now()->subMinute()]);

    app()->call([new PrepareCampaignMessages($campaign), 'handle']);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending)
        ->and($campaign->messages()->count())->toBe(12);
});

it('does not prepare a scheduled campaign before its send time', function () {
    Bus::fake();
    Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->status(CampaignStatus::Scheduled)->create(['scheduled_at' => now()->addDay()]);

    app()->call([new PrepareCampaignMessages($campaign), 'handle']);

    expect($campaign->refresh())
        ->status->toBe(CampaignStatus::Scheduled)
        ->started_at->toBeNull();
    expect($campaign->messages()->count())->toBe(0);
});

it('notifies an administrator who submitted the campaign only once when it needs approval again', function () {
    Bus::fake();
    Notification::fake();
    $admin = User::factory()->admin()->create();
    Contact::factory()->consented()->count(12)->create();
    $campaign = Campaign::factory()->status(CampaignStatus::Queued)->create(['submitted_by' => $admin->id, 'approved_recipient_count' => 10]);

    app()->call([new PrepareCampaignMessages($campaign), 'handle']);

    Notification::assertSentToTimes($admin, DatabaseNotification::class, 1);
});
