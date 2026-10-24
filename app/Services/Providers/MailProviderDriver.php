<?php

namespace App\Services\Providers;

use App\Models\EmailMessage;
use App\Models\SmtpProvider;
use Illuminate\Contracts\Mail\Mailer;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

interface MailProviderDriver
{
    /**
     * Build a mailer that sends through the given provider configuration.
     */
    public function mailer(SmtpProvider $provider): Mailer;

    /**
     * Connect and authenticate without sending a message.
     *
     * @throws TransportExceptionInterface
     */
    public function testConnection(SmtpProvider $provider): void;

    /**
     * Provider-specific headers to add to an outgoing message.
     *
     * @return array<string, string>
     */
    public function headersFor(EmailMessage $message): array;

    public function capabilities(): ProviderCapabilities;
}
