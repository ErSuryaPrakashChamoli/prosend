<?php

namespace App\Console\Commands;

use App\Jobs\SyncInboundMailbox;
use App\Models\InboundMailbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('prosend:sync-inboxes')]
#[Description('Queue a sync of every enabled inbound mailbox, to pick up replies and bounce reports')]
class SyncInboxes extends Command
{
    public function handle(): int
    {
        $queued = 0;

        InboundMailbox::acrossClients()->enabled()->each(function (InboundMailbox $mailbox) use (&$queued): void {
            SyncInboundMailbox::dispatch($mailbox);
            $queued++;
        });

        $this->components->info("{$queued} mailbox sync(s) queued.");

        return self::SUCCESS;
    }
}
