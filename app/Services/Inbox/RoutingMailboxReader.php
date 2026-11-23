<?php

namespace App\Services\Inbox;

use App\Models\InboundMailbox;
use App\Services\Inbox\Contracts\MailboxReader;

/**
 * Reads each mailbox the way it is connected: IMAP, or the Gmail / Microsoft Graph API.
 */
class RoutingMailboxReader implements MailboxReader
{
    public function __construct(private ImapMailboxReader $imap, private ApiMailboxReader $api) {}

    public function fetchNew(InboundMailbox $mailbox, int $limit): MailboxBatch
    {
        return $this->reader($mailbox)->fetchNew($mailbox, $limit);
    }

    public function testConnection(InboundMailbox $mailbox): void
    {
        $this->reader($mailbox)->testConnection($mailbox);
    }

    private function reader(InboundMailbox $mailbox): MailboxReader
    {
        return $mailbox->type === InboundMailbox::TYPE_IMAP ? $this->imap : $this->api;
    }
}
