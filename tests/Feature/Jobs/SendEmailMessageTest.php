<?php

use App\Enums\CampaignStatus;
use App\Enums\EmailPurpose;
use App\Enums\MessageStatus;
use App\Enums\ProviderHealth;
use App\Enums\SuppressionReason;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\Suppression;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Fakes\FakeMailProviderDriver;

it('hands the message to the provider and records the acceptance', function () {
    $driver = FakeMailProviderDriver::install();
    $sender = SenderIdentity::factory()->create(['name' => 'Acme', 'email' => 'news@acme.test']);
    $message = EmailMessage::factory()->for($sender)->create([
        'to_email' => 'jane@example.com',
        'subject' => 'Your receipt',
        'html_body' => '<p>Thanks for your order.</p>',
    ]);

    app()->call([new SendEmailMessage($message), 'handle']);

    expect($driver->sent)->toHaveCount(1);
    $email = $driver->sent[0]->getOriginalMessage();
    expect($email->getHeaders()->get('Message-ID')->getBodyAsString())->toBe("<{$message->uuid}@acme.test>")
        ->and($email->getHeaders()->get('From')->getBodyAsString())->toBe('Acme <news@acme.test>')
        ->and($email->getHeaders()->has('List-Unsubscribe'))->toBeFalse();
    expect($message->refresh())
        ->status->toBe(MessageStatus::Accepted)
        ->attempts->toBe(1)
        ->smtp_provider_id->toBe($sender->smtp_provider_id)
        ->accepted_at->not->toBeNull();
    expect($sender->smtpProvider->refresh()->health_status)->toBe(ProviderHealth::Healthy);
});

it('adds one-click unsubscribe headers and a personalised body to marketing email', function () {
    $driver = FakeMailProviderDriver::install();
    $contact = Contact::factory()->consented()->create(['first_name' => 'Jane']);
    $message = EmailMessage::factory()->for($contact)->create([
        'purpose' => EmailPurpose::Marketing,
        'to_email' => $contact->email,
        'subject' => 'Hello {{ first_name }}',
        'html_body' => '<p>Hi <span data-type="mergeTag" data-id="first_name"></span>, here is our news.</p>',
    ]);

    dispatch_sync(new SendEmailMessage($message));

    $email = $driver->sent[0]->getOriginalMessage();
    expect($email->getSubject())->toBe('Hello Jane')
        ->and($email->getHtmlBody())->toContain('Hi <span data-type="mergeTag" data-id="first_name">Jane</span>')
        ->and($email->getHtmlBody())->toContain('Unsubscribe')
        ->and($email->getHeaders()->get('List-Unsubscribe')->getBodyAsString())->toStartWith('<'.url('/unsubscribe/'.$message->uuid))
        ->and($email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click');
});

it('skips a suppressed recipient without contacting the provider', function () {
    $driver = FakeMailProviderDriver::install();
    Suppression::factory()->create(['email' => 'bounced@example.com', 'reason' => SuppressionReason::HardBounce]);
    $message = EmailMessage::factory()->create(['to_email' => 'bounced@example.com']);

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh())
        ->status->toBe(MessageStatus::Suppressed)
        ->error_message->toBe('bounced@example.com: suppressed (Hard bounce)');
});

it('skips marketing email to a contact without recorded consent', function () {
    $driver = FakeMailProviderDriver::install();
    $contact = Contact::factory()->create();
    $message = EmailMessage::factory()->for($contact)->create(['purpose' => EmailPurpose::Marketing, 'to_email' => $contact->email]);

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh()->status)->toBe(MessageStatus::Suppressed);
});

it('still sends transactional email to an unsubscribed address', function () {
    $driver = FakeMailProviderDriver::install();
    Suppression::factory()->create(['email' => 'left@example.com', 'reason' => SuppressionReason::Unsubscribed]);
    $message = EmailMessage::factory()->create(['to_email' => 'left@example.com']);

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toHaveCount(1);
    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

it('defers and releases the job when the provider reports a temporary error', function () {
    FakeMailProviderDriver::install()->failWith(new UnexpectedResponseException('Expected response code "250" but got code "421", with message "421 4.7.0 Try again later".', 421));
    $message = EmailMessage::factory()->create();
    $job = (new SendEmailMessage($message))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertReleased(60);
    expect($message->refresh())
        ->status->toBe(MessageStatus::Deferred)
        ->error_code->toBe('421');
});

it('marks the message failed after the last temporary-error retry', function () {
    FakeMailProviderDriver::install()->failWith(new UnexpectedResponseException('Try again later', 421));
    $message = EmailMessage::factory()->status(MessageStatus::Deferred)->create(['attempts' => 4]);
    $job = (new SendEmailMessage($message))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->is_retryable->toBeTrue();
});

it('fails without retrying when the provider permanently rejects the message', function () {
    FakeMailProviderDriver::install()->failWith(new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.1.1 Mailbox does not exist".', 550));
    $message = EmailMessage::factory()->create();
    $job = (new SendEmailMessage($message))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('550')
        ->is_retryable->toBeFalse();
});

it('records an unknown outcome instead of retrying when the connection drops after submitting the message', function () {
    $failure = new TransportException('Connection to "smtp-relay.brevo.com:587" has been closed unexpectedly.');
    $failure->appendDebug("> DATA\r\n< 354 Start mail input\r\n");
    FakeMailProviderDriver::install()->failWith($failure);
    $message = EmailMessage::factory()->create();
    $job = (new SendEmailMessage($message))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($message->refresh()->status)->toBe(MessageStatus::Unknown);
});

it('does not resend a message whose previous attempt stopped mid-send', function () {
    $driver = FakeMailProviderDriver::install();
    $message = EmailMessage::factory()->status(MessageStatus::Sending)->create(['attempts' => 1]);

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh())
        ->status->toBe(MessageStatus::Unknown)
        ->attempts->toBe(1);
});

it('does not send a message that was already accepted', function () {
    $driver = FakeMailProviderDriver::install();
    $message = EmailMessage::factory()->accepted()->create();

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toBeEmpty();
});

it('fails with a retryable error when the sender is not verified and verification is required', function () {
    config(['prosend.require_verified_senders' => true]);
    $driver = FakeMailProviderDriver::install();
    $message = EmailMessage::factory()->for(SenderIdentity::factory()->unverified())->create();

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->is_retryable->toBeTrue();
});

it('queues a fresh attempt for the next day once the provider daily limit is reached', function () {
    $driver = FakeMailProviderDriver::install();
    $provider = SmtpProvider::factory()->dailyLimit(1)->create();
    $sender = SenderIdentity::factory()->for($provider)->create();
    EmailMessage::factory()->for($sender)->accepted()->create(['smtp_provider_id' => $provider->id]);
    $message = EmailMessage::factory()->for($sender)->create();
    $this->travelTo('2026-10-24 15:00:00');
    Queue::fake([SendEmailMessage::class]);

    app()->call([new SendEmailMessage($message), 'handle']);

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh()->status)->toBe(MessageStatus::Deferred);
    Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job): bool => $job->message->is($message)
        && $job->delay->between('2026-10-25 00:01:00', '2026-10-25 00:11:00'));
});

it('spaces campaign emails waiting for the next quota window at the campaign rate', function () {
    FakeMailProviderDriver::install();
    $provider = SmtpProvider::factory()->dailyLimit(1)->create();
    $sender = SenderIdentity::factory()->for($provider)->create();
    EmailMessage::factory()->for($sender)->accepted()->create(['smtp_provider_id' => $provider->id]);
    $campaign = Campaign::factory()->for($sender)->prepared()->create(['send_rate_per_minute' => 30]);
    EmailMessage::factory()->for($campaign)->for($sender)->status(MessageStatus::Deferred)->create();
    $message = EmailMessage::factory()->for($campaign)->for($sender)->create();
    $this->travelTo('2026-10-24 15:00:00');
    Queue::fake([SendEmailMessage::class]);

    app()->call([new SendEmailMessage($message), 'handle']);

    Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job): bool => $job->delay->toDateTimeString() === '2026-10-25 00:01:02');
});

it('measures the retry window from when a delayed job becomes due', function () {
    $this->freezeSecond();
    $job = (new SendEmailMessage(EmailMessage::factory()->create()))->delay(now()->addDays(5));

    expect($job->retryUntil()->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());
});

it('leaves a message alone while another attempt is still sending it', function () {
    $driver = FakeMailProviderDriver::install();
    $message = EmailMessage::factory()->status(MessageStatus::Sending)->create(['attempts' => 1, 'sending_started_at' => now()]);

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh()->status)->toBe(MessageStatus::Sending);
});

it('keeps a delivery status that a webhook recorded while the message was being sent', function () {
    $message = EmailMessage::factory()->status(MessageStatus::Sending)->create();
    EmailMessage::query()->whereKey($message->id)->update(['status' => MessageStatus::Delivered]);

    $message->markAccepted($message->senderIdentity->smtpProvider);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Delivered)
        ->accepted_at->not->toBeNull();
});

it('leaves the message queued while its campaign is paused', function () {
    $driver = FakeMailProviderDriver::install();
    $campaign = Campaign::factory()->status(CampaignStatus::Paused)->create();
    $message = EmailMessage::factory()->for($campaign)->create();

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh()->status)->toBe(MessageStatus::Queued);
});

it('completes the campaign when its last message is accepted', function () {
    FakeMailProviderDriver::install();
    $campaign = Campaign::factory()->transactional()->prepared()->create();
    EmailMessage::factory()->for($campaign)->accepted()->create();
    $message = EmailMessage::factory()->for($campaign)->create();

    dispatch_sync(new SendEmailMessage($message));

    expect($campaign->refresh())
        ->status->toBe(CampaignStatus::Completed)
        ->completed_at->not->toBeNull();
});

it('marks the campaign partially failed when some messages failed', function () {
    FakeMailProviderDriver::install()->failWith(new UnexpectedResponseException('550 rejected', 550));
    $campaign = Campaign::factory()->transactional()->prepared()->create();
    EmailMessage::factory()->for($campaign)->accepted()->create();
    $message = EmailMessage::factory()->for($campaign)->create();

    dispatch_sync(new SendEmailMessage($message));

    expect($campaign->refresh()->status)->toBe(CampaignStatus::PartiallyFailed);
});

it('waits when the provider per-minute limit is used up', function () {
    $driver = FakeMailProviderDriver::install();
    $provider = SmtpProvider::factory()->create(['max_per_minute' => 1]);
    $sender = SenderIdentity::factory()->for($provider)->create();
    $first = EmailMessage::factory()->for($sender)->create();
    $second = EmailMessage::factory()->for($sender)->create();
    $job = (new SendEmailMessage($second))->withFakeQueueInteractions();

    app()->call([new SendEmailMessage($first), 'handle']);
    app()->call([$job, 'handle']);

    expect($driver->sent)->toHaveCount(1);
    expect($second->refresh()->status)->toBe(MessageStatus::Queued);
    $job->assertReleased();
});

it('records each hand-over attempt with its outcome', function () {
    FakeMailProviderDriver::install()->failWith(new UnexpectedResponseException('421 Try again later', 421));
    $message = EmailMessage::factory()->create();
    $job = (new SendEmailMessage($message))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);
    FakeMailProviderDriver::install();
    app()->call([new SendEmailMessage($message->refresh()), 'handle']);

    expect($message->sendAttempts()->orderBy('attempt')->get()->map->only(['attempt', 'outcome', 'code'])->all())->toBe([
        ['attempt' => 1, 'outcome' => 'deferred', 'code' => '421'],
        ['attempt' => 2, 'outcome' => 'accepted', 'code' => null],
    ]);
});
