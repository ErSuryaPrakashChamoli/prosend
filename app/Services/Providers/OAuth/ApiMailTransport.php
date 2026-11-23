<?php

namespace App\Services\Providers\OAuth;

use App\Enums\ProviderType;
use App\Models\SmtpProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Throwable;

/**
 * Sends the complete MIME message through the Gmail API or Microsoft Graph, so the Message-ID, threading and
 * unsubscribe headers ProSend sets travel unchanged. Failures are reported as TransportException with the HTTP status
 * as the code, which the driver classifies.
 *
 * Both APIs take the recipients from the message's headers rather than an envelope, so Bcc recipients are written back
 * as a Bcc header (Symfony leaves it out of the rendered message); both services remove it before delivering.
 */
class ApiMailTransport extends AbstractTransport
{
    public function __construct(private SmtpProvider $provider, private OAuthClient $oauth)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $mime = $this->withBcc($message);

        try {
            $token = $this->oauth->accessToken($this->provider);
        } catch (OAuthRevoked $exception) {
            throw new TransportException($exception->getMessage(), 401, $exception);
        } catch (Throwable $exception) {
            // The token could not be refreshed (the sign-in service failed or timed out), so nothing was sent: retry later.
            throw new TransportException('The mailbox sign-in could not be refreshed: '.$exception->getMessage(), 503, $exception);
        }

        try {
            $response = $this->provider->type === ProviderType::Gmail
                ? Http::withToken($token)->timeout(30)->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                    'raw' => rtrim(strtr(base64_encode($mime), '+/', '-_'), '='),
                ])
                : Http::withToken($token)->timeout(30)->withBody(base64_encode($mime), 'text/plain')->post('https://graph.microsoft.com/v1.0/me/sendMail');
        } catch (ConnectionException $exception) {
            // The request may have reached the API before the connection broke, so the outcome is unknown.
            throw new TransportException('The connection to the mailbox API broke: '.$exception->getMessage(), 0, $exception);
        }

        if (! $response->successful()) {
            $detail = $response->json('error.message') ?? $response->json('error.code') ?? '';

            throw new TransportException(trim("The mailbox API answered {$response->status()}. {$detail}"), $response->status());
        }
    }

    private function withBcc(SentMessage $message): string
    {
        $original = $message->getOriginalMessage();
        $bcc = $original instanceof Email ? $original->getBcc() : [];

        return $bcc === [] ? $message->toString() : (new MailboxListHeader('Bcc', $bcc))->toString()."\r\n".$message->toString();
    }

    public function __toString(): string
    {
        return $this->provider->type === ProviderType::Gmail ? 'gmail+api://' : 'msgraph+api://';
    }
}
