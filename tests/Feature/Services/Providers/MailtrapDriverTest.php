<?php

use App\Enums\SendFailureKind;
use App\Models\SmtpProvider;
use App\Services\Providers\MailtrapDriver;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

it('retries the sandbox rate limit instead of failing the email or blaming the provider', function () {
    $provider = SmtpProvider::factory()->mailtrapSandbox()->make();
    $exception = new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.7.0 Requested action not taken: too many emails per second".', 550);

    $failure = app(MailtrapDriver::class)->classifyFailure($exception, $provider);

    expect($failure->kind)->toBe(SendFailureKind::Transient)
        ->and($failure->code)->toBe('550')
        ->and($failure->retryable)->toBeTrue()
        ->and($failure->affectsProvider())->toBeFalse()
        ->and($failure->summary)->toStartWith('Mailtrap rate limit reached; the email will be retried.');
});

it('treats the connection limit as throttling, not as rejected credentials', function () {
    $provider = SmtpProvider::factory()->mailtrap()->make();
    $exception = new UnexpectedResponseException('Expected response code "235" but got code "535", with message "535 5.7.8 Too many connections per account".', 535);

    $failure = app(MailtrapDriver::class)->classifyFailure($exception, $provider);

    expect($failure->kind)->toBe(SendFailureKind::Transient)
        ->and($failure->affectsProvider())->toBeFalse()
        ->and($failure->summary)->toContain('Run fewer queue workers')
        ->and($failure->summary)->not->toContain('rejected the SMTP credentials');
});

it('reports a real authentication failure against the provider without revealing the token', function () {
    $provider = SmtpProvider::factory()->mailtrap()->make(['password' => 'mt-secret-token']);
    $exception = new TransportException('Failed to authenticate on SMTP server with username "api" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "535 5.7.8 Authentication failed for mt-secret-token".', 535);

    $failure = app(MailtrapDriver::class)->classifyFailure($exception, $provider);

    expect($failure->affectsProvider())->toBeTrue()
        ->and($failure->summary)->toStartWith('The provider rejected the SMTP credentials.')
        ->and($failure->summary)->toContain('username "api"')
        ->and($failure->summary)->not->toContain('mt-secret-token');
});

it('leaves a rejected recipient as a permanent failure of that email only', function () {
    $provider = SmtpProvider::factory()->mailtrap()->make();
    $exception = new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.1.1 Mailbox does not exist".', 550);

    $failure = app(MailtrapDriver::class)->classifyFailure($exception, $provider);

    expect($failure->kind)->toBe(SendFailureKind::Permanent)
        ->and($failure->retryable)->toBeFalse()
        ->and($failure->affectsProvider())->toBeFalse();
});
