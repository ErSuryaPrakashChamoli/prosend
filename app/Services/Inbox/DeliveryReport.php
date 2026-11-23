<?php

namespace App\Services\Inbox;

/**
 * What a delivery status notification (a bounce report, RFC 3464) says about an email ProSend sent.
 */
final readonly class DeliveryReport
{
    public function __construct(
        public bool $permanent,
        public ?string $status,
        public ?string $recipient,
        public ?string $originalMessageId,
        public ?string $diagnostic,
    ) {}
}
