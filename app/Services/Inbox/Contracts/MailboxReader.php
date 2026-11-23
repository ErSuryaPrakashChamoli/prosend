<?php

namespace App\Services\Inbox\Contracts;

use App\Models\InboundMailbox;
use App\Services\Inbox\MailboxBatch;

/**
 * Reads new email from an inbound mailbox, without marking it as read.
 */
interface MailboxReader
{
    /**
     * Email received since the mailbox's last sync, oldest first, at most $limit of them.
     */
    public function fetchNew(InboundMailbox $mailbox, int $limit): MailboxBatch;

    /**
     * Sign in and open the folder, without reading anything.
     *
     * @throws \Throwable when the mailbox cannot be reached or the credentials are refused.
     */
    public function testConnection(InboundMailbox $mailbox): void;
}
