<?php

namespace App\Services\Providers;

use App\Enums\SendFailureKind;
use App\Models\EmailMessage;
use App\Models\SmtpProvider;
use App\Services\Sending\SendFailure;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Throwable;

class SmtpDriver implements MailProviderDriver
{
    public function __construct(
        protected MailManager $mailManager,
        protected SmtpHostPolicy $hostPolicy,
    ) {}

    /**
     * The connection goes to the address the host policy checked, while TLS still verifies the certificate
     * against the host name, so DNS cannot point the connection somewhere else after the check.
     */
    public function mailer(SmtpProvider $provider): Mailer
    {
        $mailer = $this->mailManager->build($this->transportConfig($provider));
        $transport = $mailer->getSymfonyTransport();
        $stream = $transport instanceof SmtpTransport ? $transport->getStream() : null;

        if ($stream instanceof SocketStream) {
            $address = $this->hostPolicy->addressFor($provider->host);

            $stream->setHost(str_contains($address, ':') ? "[{$address}]" : $address);
            $stream->setStreamOptions(array_replace_recursive($stream->getStreamOptions(), ['ssl' => ['peer_name' => $provider->host]]));
        }

        return $mailer;
    }

    public function testConnection(SmtpProvider $provider): void
    {
        $transport = $this->mailer($provider)->getSymfonyTransport();

        if ($transport instanceof SmtpTransport) {
            $transport->start();
            $transport->stop();
        }
    }

    public function headersFor(EmailMessage $message): array
    {
        return [];
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities;
    }

    public function classifyFailure(Throwable $exception, SmtpProvider $provider): SendFailure
    {
        // A configuration problem: retried on the usual schedule, so fixing the setting lets waiting email go out.
        if ($exception instanceof BlockedSmtpHostException) {
            return new SendFailure(SendFailureKind::Transient, $exception->getMessage(), retryable: true, providerProblem: true);
        }

        return SendFailure::fromException($exception, $this->secrets($provider));
    }

    /**
     * Values never to show in an error message.
     *
     * @return list<string>
     */
    protected function secrets(SmtpProvider $provider): array
    {
        return array_values(array_filter([(string) $provider->username, (string) $provider->password]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function transportConfig(SmtpProvider $provider): array
    {
        return [
            'transport' => 'smtp',
            ...$provider->encryption->transportOptions(),
            'host' => $provider->host,
            'port' => $provider->port,
            'username' => $provider->username,
            'password' => $provider->password,
            'timeout' => config('prosend.sending.connection_timeout'),
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
        ];
    }
}
