<?php

namespace App\Services\Inbox;

use Carbon\CarbonImmutable;

/**
 * An email read from an inbound mailbox, reduced to what ProSend needs.
 */
final readonly class InboundEmail
{
    /**
     * @param  list<string>  $references
     * @param  list<string>  $to
     * @param  list<array{name: string, mime: string, content: string}>  $attachments
     */
    public function __construct(
        public ?string $messageId,
        public ?string $inReplyTo,
        public array $references,
        public string $fromEmail,
        public ?string $fromName,
        public array $to,
        public string $subject,
        public string $text,
        public ?string $html,
        public CarbonImmutable $receivedAt,
        public bool $isAutoReply,
        public ?DeliveryReport $deliveryReport,
        public array $attachments = [],
    ) {}

    /**
     * Every Message-ID this email refers to, most recent first.
     *
     * @return list<string>
     */
    public function referencedIds(): array
    {
        return array_values(array_unique(array_filter([$this->inReplyTo, ...array_reverse($this->references)])));
    }
}
