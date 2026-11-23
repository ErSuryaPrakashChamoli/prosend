<?php

namespace App\Services\Inbox;

use App\Enums\SmtpEncryption;
use App\Models\InboundMailbox;
use App\Services\Inbox\Contracts\MailboxReader;
use App\Services\Providers\SmtpHostPolicy;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Reads a mailbox over IMAP with webklex/php-imap's protocol layer.
 *
 * The folder is opened with EXAMINE, which is read-only, so reading never marks email as read or changes any flag. Only
 * the raw email is fetched; parsing happens afterwards in MimeParser, where a broken email affects nothing but itself.
 * At most $limit emails are fetched per sync, oldest first, and emails over MAX_MESSAGE_BYTES are skipped. The first
 * sync reads the last 14 days; later ones read everything after the last UID seen, starting over (from 14 days back)
 * if the server reset its UIDs.
 *
 * The host is checked against the same rules as SMTP hosts before every connection. Unlike SMTP, the connection cannot
 * be pinned to the checked address, so a host whose DNS changes between the check and the connection is not caught.
 */
class ImapMailboxReader implements MailboxReader
{
    public const FIRST_SYNC_DAYS = 14;

    public const MAX_MESSAGE_BYTES = 25 * 1024 * 1024;

    public function __construct(private SmtpHostPolicy $hostPolicy) {}

    public function fetchNew(InboundMailbox $mailbox, int $limit): MailboxBatch
    {
        $client = $this->connect($mailbox);

        try {
            $connection = $client->getConnection();
            $status = $connection->examineFolder($mailbox->folder ?: 'INBOX')->validatedData();
            $uidValidity = (int) ($status['uidvalidity'] ?? 0) ?: null;
            $continuing = $mailbox->last_uid !== null && $mailbox->uid_validity === $uidValidity;

            $uids = $continuing
                ? $connection->search(['UID', ($mailbox->last_uid + 1).':*'])->validatedData()
                : $connection->search(['SINCE', now()->subDays(self::FIRST_SYNC_DAYS)->format('j-M-Y')])->validatedData();

            // "n:*" always matches the highest UID, even when it is below n.
            $uids = collect((array) $uids)
                ->map(fn (mixed $uid): int => (int) $uid)
                ->filter(fn (int $uid): bool => ! $continuing || $uid > $mailbox->last_uid)
                ->unique()
                ->sort()
                ->take($limit)
                ->values();

            $raw = [];

            foreach ($uids as $uid) {
                $size = (int) $connection->fetch(['RFC822.SIZE'], $uid)->validatedData();

                // Too large to process: recorded as empty so the cursor moves past it.
                $raw[$uid] = $size > self::MAX_MESSAGE_BYTES ? '' : (string) $connection->fetch(['RFC822'], $uid)->validatedData();
            }

            return new MailboxBatch($raw, $uidValidity);
        } finally {
            $client->disconnect();
        }
    }

    public function testConnection(InboundMailbox $mailbox): void
    {
        $client = $this->connect($mailbox);

        try {
            $client->getConnection()->examineFolder($mailbox->folder ?: 'INBOX')->validatedData();
        } finally {
            $client->disconnect();
        }
    }

    private function connect(InboundMailbox $mailbox): Client
    {
        $problem = $this->hostPolicy->problemWith((string) $mailbox->host);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        return (new ClientManager)->make([
            'host' => $mailbox->host,
            'port' => $mailbox->port ?? ($mailbox->encryption === SmtpEncryption::Tls ? 993 : 143),
            'protocol' => 'imap',
            'encryption' => match ($mailbox->encryption) {
                SmtpEncryption::Tls => 'ssl',
                SmtpEncryption::StartTls => 'starttls',
                default => false,
            },
            'validate_cert' => true,
            'username' => $mailbox->username ?: $mailbox->email,
            'password' => (string) $mailbox->password,
            'timeout' => (int) config('prosend.sending.connection_timeout', 30),
        ])->connect();
    }
}
