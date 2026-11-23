<?php

namespace App\Services\Sending;

use App\Enums\MessageSource;
use App\Mail\OutgoingEmail;
use App\Models\ConversationMessage;
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
            ->send(new OutgoingEmail($message, $this->renderer->render($message), [...$driver->headersFor($message), ...$this->threadingHeaders($message)]));
    }

    /**
     * A follow-up without a subject of its own replies to the earlier emails of its sequence, so mail apps show one
     * conversation.
     *
     * @return array<string, string>
     */
    public function threadingHeaders(EmailMessage $message): array
    {
        if ($message->source === MessageSource::Inbox) {
            return $this->inboxReplyHeaders($message);
        }

        // A follow-up starts a new thread when it, or its A/B version, has a subject of its own.
        if ($message->step_number <= 1 || $message->campaignStep === null || ! $message->campaignStep->repliesInThread() || filled($message->campaignVariant?->subject)) {
            return [];
        }

        $earlier = EmailMessage::query()
            ->where('campaign_id', $message->campaign_id)
            ->where('contact_id', $message->contact_id)
            ->where('step_number', '<', $message->step_number)
            ->whereNotNull('provider_message_id')
            ->orderBy('step_number')
            ->pluck('provider_message_id')
            ->map(fn (string $id): string => "<{$id}>")
            ->all();

        if ($earlier === []) {
            return [];
        }

        return [
            'In-Reply-To' => end($earlier),
            'References' => implode(' ', $earlier),
        ];
    }

    /**
     * An answer sent from the inbox continues the prospect's thread.
     *
     * @return array<string, string>
     */
    private function inboxReplyHeaders(EmailMessage $message): array
    {
        $entry = ConversationMessage::query()->where('email_message_id', $message->getKey())->first();

        if ($entry?->in_reply_to === null) {
            return [];
        }

        $references = array_values(array_unique(array_filter([...preg_split('/\s+/', (string) $entry->references) ?: [], $entry->in_reply_to])));

        return [
            'In-Reply-To' => "<{$entry->in_reply_to}>",
            'References' => implode(' ', array_map(fn (string $id): string => "<{$id}>", $references)),
        ];
    }
}
