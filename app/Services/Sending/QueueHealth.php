<?php

namespace App\Services\Sending;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Detects a queue with no worker. Every email is sent from the queue, so when no worker is running,
 * messages stay "Queued" forever without any error. Only the database queue can be inspected.
 */
class QueueHealth
{
    /**
     * A due job left untouched this long, while nothing else is being processed, means no worker is running.
     * Jobs held back by rate limits or schedules are not due yet, so they never count.
     */
    public const STALLED_AFTER_SECONDS = 300;

    /**
     * @return array{waiting: int, waiting_since: Carbon}|null
     */
    public function stalled(): ?array
    {
        $connection = config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return null;
        }

        $jobs = DB::connection(config("queue.connections.{$connection}.connection"))
            ->table(config("queue.connections.{$connection}.table", 'jobs'));

        $isProcessing = (clone $jobs)
            ->where('reserved_at', '>', now()->subSeconds((int) config("queue.connections.{$connection}.retry_after", 90))->getTimestamp())
            ->exists();

        if ($isProcessing) {
            return null;
        }

        $stalled = $jobs
            ->where('available_at', '<=', now()->subSeconds(self::STALLED_AFTER_SECONDS)->getTimestamp())
            ->selectRaw('count(*) as waiting, min(available_at) as waiting_since')
            ->first();

        if ($stalled === null || (int) $stalled->waiting === 0) {
            return null;
        }

        return [
            'waiting' => (int) $stalled->waiting,
            'waiting_since' => Carbon::createFromTimestamp((int) $stalled->waiting_since),
        ];
    }
}
