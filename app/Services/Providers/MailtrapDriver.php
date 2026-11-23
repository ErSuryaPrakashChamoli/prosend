<?php

namespace App\Services\Providers;

use App\Enums\SendFailureKind;
use App\Models\SmtpProvider;
use App\Services\Sending\SendFailure;
use Throwable;

/**
 * Mailtrap Email Sending and Email Sandbox, over SMTP. Mailtrap reports throttling with codes that would
 * otherwise read as a rejected message or rejected credentials, so those replies are retried instead.
 *
 * Mailtrap's own delivery webhooks are not processed yet, so a live message stays "Accepted by provider".
 */
class MailtrapDriver extends SmtpDriver
{
    public function classifyFailure(Throwable $exception, SmtpProvider $provider): SendFailure
    {
        $reply = str_replace($this->secrets($provider), '[redacted]', $exception->getMessage());
        $code = $exception->getCode() > 0 ? (string) $exception->getCode() : null;

        // Sandbox: "550 5.7.0 Requested action not taken: too many emails per second" (a per-10-second plan limit).
        if (str_contains(strtolower($reply), 'too many emails per second')) {
            return new SendFailure(
                SendFailureKind::Transient,
                "Mailtrap rate limit reached; the email will be retried. Lower \"Emails per minute\" for this provider to match your Mailtrap plan. {$reply}",
                $code,
                retryable: true,
                providerProblem: false,
            );
        }

        // Email Sending: "535 5.7.8 Too many connections per account" (10 connections per account and per IP).
        if (str_contains(strtolower($reply), 'too many connections')) {
            return new SendFailure(
                SendFailureKind::Transient,
                "Mailtrap connection limit reached; the email will be retried. Run fewer queue workers for this provider. {$reply}",
                $code,
                retryable: true,
                providerProblem: false,
            );
        }

        return parent::classifyFailure($exception, $provider);
    }

    /**
     * Email Sending always signs in as "api". It is not secret, and removing it would garble error messages.
     */
    protected function secrets(SmtpProvider $provider): array
    {
        return array_values(array_diff(parent::secrets($provider), ['api']));
    }
}
