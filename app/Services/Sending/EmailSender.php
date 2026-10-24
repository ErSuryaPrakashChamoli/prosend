<?php

namespace App\Services\Sending;

use App\Mail\OutgoingEmail;
use App\Models\EmailMessage;
use App\Models\SmtpProvider;
use App\Services\Providers\MailProviderManager;
use Illuminate\Mail\Mailables\Address;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;

class EmailSender
{
    public function __construct(
        private MailProviderManager $providers,
        private EmailContentRenderer $renderer,
    ) {}

    /**
     * Hand the message to the provider. Returns normally only when the provider accepted it.
     *
     * @throws TransportExceptionInterface
     * @throws RfcComplianceException
     */
    public function send(EmailMessage $message, SmtpProvider $provider): void
    {
        $driver = $this->providers->driver($provider);

        $message->ensureProviderMessageId();

        $driver->mailer($provider)
            ->to(new Address($message->to_email, $message->to_name))
            ->cc($message->cc ?? [])
            ->bcc($message->bcc ?? [])
            ->send(new OutgoingEmail($message, $this->renderer->render($message), $driver->headersFor($message)));
    }
}
