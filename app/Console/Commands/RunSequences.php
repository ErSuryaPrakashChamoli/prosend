<?php

namespace App\Console\Commands;

use App\Services\Campaigns\SequenceRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('prosend:run-sequences')]
#[Description('Queue the follow-up emails that are due in active campaigns')]
class RunSequences extends Command
{
    public function handle(SequenceRunner $runner): int
    {
        $queued = $runner->run();

        $this->components->info("{$queued} follow-up email(s) queued.");

        return self::SUCCESS;
    }
}
