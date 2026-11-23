<?php

namespace App\Services\Inbox;

use App\Models\InboundMailbox;
use App\Services\Inbox\Contracts\MailboxReader;
use App\Services\Tenancy\CurrentClient;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a mailbox's new email and processes each one, as the mailbox's client. One unreadable email never stops the
 * rest, and the mailbox only moves past emails that were processed.
 */
class InboxSynchronizer
{
    public const BATCH_SIZE = 200;

    public function __construct(
        private MailboxReader $reader,
        private MimeParser $parser,
        private InboundEmailProcessor $processor,
        private CurrentClient $currentClient,
    ) {}

    /**
     * @return array<string, int> How many emails had each outcome.
     */
    public function sync(InboundMailbox $mailbox): array
    {
        return $this->currentClient->runAsClientId($mailbox->client_id, fn (): array => $this->run($mailbox));
    }

    /**
     * @return array<string, int>
     */
    private function run(InboundMailbox $mailbox): array
    {
        try {
            $batch = $this->reader->fetchNew($mailbox, self::BATCH_SIZE);
        } catch (Throwable $exception) {
            $mailbox->recordSyncFailure($this->describe($exception));

            return [];
        }

        $outcomes = [];

        // After the server resets its UIDs, the old position means nothing, even when no email arrived since.
        if ($mailbox->uid_validity !== $batch->uidValidity) {
            $mailbox->forceFill(['last_uid' => null, 'uid_validity' => $batch->uidValidity])->save();
        }

        $messages = $batch->messages;
        ksort($messages);

        foreach ($messages as $uid => $raw) {
            try {
                $outcome = $raw === '' ? InboundOutcome::Unreadable : $this->processor->process($mailbox, $this->parser->parse($raw));
            } catch (Throwable $exception) {
                report($exception);
                $outcome = InboundOutcome::Unreadable;
            }

            $outcomes[$outcome->value] = ($outcomes[$outcome->value] ?? 0) + 1;

            // Saved after every email, so a sync that stops part-way keeps its progress.
            $mailbox->forceFill(['last_uid' => max((int) $mailbox->last_uid, $uid)])->save();
        }

        $mailbox->recordSyncSuccess();

        return $outcomes;
    }

    /**
     * Connection errors can echo the username; the password is never part of them.
     */
    private function describe(Throwable $exception): string
    {
        return class_basename($exception).': '.Str::limit($exception->getMessage(), 500);
    }
}
