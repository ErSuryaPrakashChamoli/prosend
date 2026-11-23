<?php

namespace App\Services\Providers;

use App\Enums\SendFailureKind;
use App\Models\EmailMessage;
use App\Models\SmtpProvider;
use App\Services\Providers\OAuth\ApiMailTransport;
use App\Services\Providers\OAuth\OAuthClient;
use App\Services\Sending\SendFailure;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Contracts\View\Factory;
use Illuminate\Events\Dispatcher;
use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * A mailbox connected with OAuth (Gmail or Microsoft 365), sending through the provider's HTTPS API. These APIs only
 * report acceptance, so delivery stays "Accepted by provider"; replies and bounce reports arrive in the connected inbox.
 */
abstract class ApiMailboxDriver implements MailProviderDriver
{
    public function __construct(private OAuthClient $oauth) {}

    public function mailer(SmtpProvider $provider): MailerContract
    {
        return new Mailer('oauth-'.$provider->getKey(), app(Factory::class), new ApiMailTransport($provider, $this->oauth), app(Dispatcher::class));
    }

    /**
     * Refreshing the token and reading the account proves the connection works.
     */
    public function testConnection(SmtpProvider $provider): void
    {
        $this->oauth->accountEmail($provider->type, $this->oauth->accessToken($provider));
    }

    public function headersFor(EmailMessage $message): array
    {
        return [];
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities;
    }

    /**
     * 401/403: the connection needs attention (retryable once fixed); 429 and 5xx: temporary; other 4xx: rejected;
     * anything else (a lost connection): the API may or may not have sent it.
     */
    public function classifyFailure(Throwable $exception, SmtpProvider $provider): SendFailure
    {
        $status = $exception instanceof TransportExceptionInterface ? (int) $exception->getCode() : 0;
        $summary = mb_substr($exception->getMessage(), 0, 500);

        return match (true) {
            in_array($status, [401, 403], true) => new SendFailure(SendFailureKind::Permanent, $summary, (string) $status, retryable: true, providerProblem: true),
            $status === 429 || $status >= 500 => new SendFailure(SendFailureKind::Transient, $summary, (string) $status, retryable: true),
            $status >= 400 => new SendFailure(SendFailureKind::Permanent, $summary, (string) $status),
            default => new SendFailure(SendFailureKind::Ambiguous, $summary),
        };
    }
}
