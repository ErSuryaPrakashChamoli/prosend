<?php

use App\Actions\Messages\RetryEmailMessage;
use App\Enums\EmailEventType;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Enums\ProviderHealth;
use App\Enums\ProviderType;
use App\Jobs\SendEmailMessage;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailMessageAttempt;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Services\Analytics\EmailMetrics;
use App\Services\Providers\MailProviderManager;
use App\Services\Providers\MailtrapDriver;
use App\Services\Sending\SendFailure;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Fakes\FakeMailProviderDriver;

function sandboxMessage(array $attributes = []): EmailMessage
{
    $sender = SenderIdentity::factory()->for(SmtpProvider::factory()->mailtrapSandbox())->create(['email' => 'qa@acme.test']);

    return EmailMessage::factory()->for($sender)->create(['status' => MessageStatus::Queued, 'attempts' => 0, 'accepted_at' => null, 'is_sandbox' => null, ...$attributes]);
}

it('records email handed to a sandbox as captured, never as accepted or delivered', function () {
    $driver = FakeMailProviderDriver::install();
    $contact = Contact::factory()->create();
    $message = sandboxMessage(['contact_id' => $contact->id, 'to_email' => $contact->email, 'source' => MessageSource::Compose]);

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toHaveCount(1);
    expect($message->refresh())
        ->status->toBe(MessageStatus::Captured)
        ->is_sandbox->toBeTrue()
        ->smtp_provider_id->toBe($message->senderIdentity->smtp_provider_id)
        ->captured_at->not->toBeNull()
        ->accepted_at->toBeNull()
        ->delivered_at->toBeNull();
    expect($message->events()->pluck('type')->all())->toContain(EmailEventType::Captured)
        ->not->toContain(EmailEventType::Accepted);
    expect($message->sendAttempts()->sole()->outcome)->toBe(EmailMessageAttempt::OUTCOME_CAPTURED);
    expect($contact->refresh()->last_contacted_at)->toBeNull();
});

it('sends live Mailtrap email as accepted by provider, which is not delivery', function () {
    FakeMailProviderDriver::install();
    $sender = SenderIdentity::factory()->for(SmtpProvider::factory()->mailtrap())->create();
    $message = EmailMessage::factory()->for($sender)->create(['status' => MessageStatus::Queued, 'attempts' => 0, 'accepted_at' => null]);

    dispatch_sync(new SendEmailMessage($message));

    expect($message->refresh())
        ->status->toBe(MessageStatus::Accepted)
        ->delivered_at->toBeNull();
});

it('refuses to send through a sandbox where sandbox providers are turned off', function () {
    $driver = FakeMailProviderDriver::install();
    config(['prosend.providers.allow_sandbox' => false]);
    $message = sandboxMessage();

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->is_retryable->toBeTrue()
        ->error_message->toContain('PROSEND_ALLOW_SANDBOX_PROVIDERS');
});

it('retries the Mailtrap rate limit with the same provider and keeps the provider healthy', function () {
    $driver = new class extends FakeMailProviderDriver
    {
        public function classifyFailure(Throwable $exception, SmtpProvider $provider): SendFailure
        {
            return app(MailtrapDriver::class)->classifyFailure($exception, $provider);
        }
    };
    app(MailProviderManager::class)->extend(ProviderType::MailtrapSandbox, $driver);
    $driver->failWith(new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.7.0 Requested action not taken: too many emails per second".', 550));
    $message = sandboxMessage();
    $message->senderIdentity->smtpProvider->recordConnectionSuccess();

    $job = (new SendEmailMessage($message))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(60);
    expect($message->refresh())
        ->status->toBe(MessageStatus::Deferred)
        ->error_message->toStartWith('Mailtrap rate limit reached');
    expect($message->senderIdentity->smtpProvider->refresh()->health_status)->toBe(ProviderHealth::Healthy);
    expect($message->sendAttempts()->sole())
        ->smtp_provider_id->toBe($message->senderIdentity->smtp_provider_id)
        ->outcome->toBe(EmailMessageAttempt::OUTCOME_DEFERRED);
});

it('counts sandbox email against the sandbox\'s own limits but leaves it out of delivery reports', function () {
    FakeMailProviderDriver::install();
    $message = sandboxMessage();
    $provider = $message->senderIdentity->smtpProvider;
    $provider->update(['daily_limit' => 10]);

    dispatch_sync(new SendEmailMessage($message));

    expect($provider->refresh()->remainingDailyQuota())->toBe(9);
    expect(app(EmailMetrics::class)->summary([]))
        ->submitted->toBe(0)
        ->accepted->toBe(0);
    expect(array_sum(app(EmailMetrics::class)->acceptedPerHour()))->toBe(0);
});

it('never sends a retried test email for real after its sender moved to a live provider', function () {
    $driver = FakeMailProviderDriver::install();
    config(['prosend.providers.allow_sandbox' => false]);
    $message = sandboxMessage();

    dispatch_sync(new SendEmailMessage($message));

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->is_sandbox->toBeTrue();

    // Nothing is waiting any more, so the move is allowed.
    $message->senderIdentity->update(['smtp_provider_id' => SmtpProvider::factory()->create(['name' => 'Brevo live'])->id]);
    app(RetryEmailMessage::class)->handle($message->refresh());

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->is_retryable->toBeFalse()
        ->error_message->toStartWith('This email was created for sandbox testing, but qa@acme.test now sends through the live provider Brevo live.');
});

it('never captures a retried live email after its sender moved to a sandbox', function () {
    $driver = FakeMailProviderDriver::install()->failWith(new UnexpectedResponseException('550 5.1.1 Mailbox does not exist', 550));
    $sender = SenderIdentity::factory()->create(['email' => 'billing@acme.test']);
    $message = EmailMessage::factory()->for($sender)->create(['status' => MessageStatus::Queued, 'attempts' => 0, 'accepted_at' => null, 'is_sandbox' => null]);

    dispatch_sync(new SendEmailMessage($message));

    expect($message->refresh()->is_sandbox)->toBeFalse();

    $sender->update(['smtp_provider_id' => SmtpProvider::factory()->mailtrapSandbox()->create()->id]);
    $message->resetForRetry();
    dispatch_sync(new SendEmailMessage($message));

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_message->toStartWith('This email was created for live sending');
    expect($driver->sent)->toBeEmpty();
});

it('refuses to send through a provider whose host does not match its type', function () {
    $driver = FakeMailProviderDriver::install();
    $mislabelled = SmtpProvider::factory()->create(['name' => 'Old import', 'type' => ProviderType::Smtp, 'host' => 'sandbox.smtp.mailtrap.io.']);
    $message = EmailMessage::factory()->for(SenderIdentity::factory()->for($mislabelled))->create(['status' => MessageStatus::Queued, 'attempts' => 0, 'accepted_at' => null]);

    dispatch_sync(new SendEmailMessage($message));

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->is_retryable->toBeTrue()
        ->error_message->toBe('The provider Old import cannot send: Choose a Mailtrap provider type for Mailtrap hosts, so sandbox and live sending are kept apart. Fix its host or type.');
});
