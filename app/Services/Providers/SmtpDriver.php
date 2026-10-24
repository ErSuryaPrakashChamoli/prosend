<?php

namespace App\Services\Providers;

use App\Models\EmailMessage;
use App\Models\SmtpProvider;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

class SmtpDriver implements MailProviderDriver
{
    public function __construct(protected MailManager $mailManager) {}

    public function mailer(SmtpProvider $provider): Mailer
    {
        return $this->mailManager->build($this->transportConfig($provider));
    }

    public function testConnection(SmtpProvider $provider): void
    {
        $transport = $this->mailManager->build($this->transportConfig($provider))->getSymfonyTransport();

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
