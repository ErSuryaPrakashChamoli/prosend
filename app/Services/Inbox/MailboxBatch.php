<?php

namespace App\Services\Inbox;

/**
 * Raw emails read from a mailbox in one sync, keyed by their IMAP UID, with the folder's UIDVALIDITY.
 */
final readonly class MailboxBatch
{
    /**
     * @param  array<int, string>  $messages  UID => raw RFC 822 email
     */
    public function __construct(
        public array $messages,
        public ?int $uidValidity,
    ) {}
}
