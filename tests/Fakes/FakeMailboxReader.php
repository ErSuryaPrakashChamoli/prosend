<?php

namespace Tests\Fakes;

use App\Models\InboundMailbox;
use App\Services\Inbox\Contracts\MailboxReader;
use App\Services\Inbox\MailboxBatch;
use Throwable;

/**
 * Serves raw emails as if they were waiting in the mailbox, after the last UID the mailbox has seen.
 */
class FakeMailboxReader implements MailboxReader
{
    /**
     * @var array<int, string>
     */
    public array $messages = [];

    public int $uidValidity = 1;

    public ?Throwable $failure = null;

    public static function install(): self
    {
        $reader = new self;
        app()->instance(MailboxReader::class, $reader);

        return $reader;
    }

    public function deliver(string $raw): self
    {
        $this->messages[count($this->messages) + 1] = $raw;

        return $this;
    }

    public function fetchNew(InboundMailbox $mailbox, int $limit): MailboxBatch
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $after = $mailbox->uid_validity === $this->uidValidity ? (int) $mailbox->last_uid : 0;

        return new MailboxBatch(
            array_slice(array_filter($this->messages, fn (int $uid): bool => $uid > $after, ARRAY_FILTER_USE_KEY), 0, $limit, preserve_keys: true),
            $this->uidValidity,
        );
    }

    public function testConnection(InboundMailbox $mailbox): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
