<?php

namespace App\Mail;

use App\Models\EmailMessage;
use App\Services\Sending\RenderedEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class OutgoingEmail extends Mailable
{
    /**
     * @param  array<string, string>  $providerHeaders
     */
    public function __construct(
        public EmailMessage $emailMessage,
        public RenderedEmail $rendered,
        private array $providerHeaders = [],
    ) {}

    public function envelope(): Envelope
    {
        $sender = $this->emailMessage->senderIdentity;
        $replyTo = $this->emailMessage->reply_to ?? $sender->reply_to;

        return new Envelope(
            from: new Address($sender->email, $sender->name),
            replyTo: $replyTo ? [new Address($replyTo)] : [],
            subject: $this->rendered->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.outgoing',
            text: 'mail.outgoing-text',
            with: ['senderName' => $this->emailMessage->senderIdentity->name],
        );
    }

    /**
     * Marketing email carries one-click unsubscribe headers (RFC 8058), which Gmail and Yahoo require for bulk senders.
     */
    public function headers(): Headers
    {
        $headers = $this->providerHeaders;

        if ($this->rendered->unsubscribeUrl !== null) {
            $headers['List-Unsubscribe'] = "<{$this->rendered->unsubscribeUrl}>";
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return new Headers(
            messageId: $this->emailMessage->provider_message_id,
            text: $headers,
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $attachment): Attachment => Attachment::fromStorageDisk(config('prosend.attachments.disk'), $attachment['path'])
                ->as($attachment['name']),
            $this->emailMessage->attachments ?? [],
        );
    }
}
