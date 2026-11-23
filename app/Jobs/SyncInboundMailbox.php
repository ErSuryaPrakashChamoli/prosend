<?php

namespace App\Jobs;

use App\Models\InboundMailbox;
use App\Services\Inbox\InboxSynchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Reads one inbound mailbox. Two syncs of the same mailbox never run at once, so an email is never processed twice.
 */
class SyncInboundMailbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public InboundMailbox $mailbox)
    {
        $this->onQueue(config('prosend.sending.queue'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("inbound-mailbox:{$this->mailbox->getKey()}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(InboxSynchronizer $synchronizer): void
    {
        $mailbox = $this->mailbox->refresh();

        if ($mailbox->is_enabled) {
            $synchronizer->sync($mailbox);
        }
    }
}
