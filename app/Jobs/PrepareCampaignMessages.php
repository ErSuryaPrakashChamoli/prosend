<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Turns a launched campaign's eligible audience into queued messages, in chunks, at send time.
 * Safe to run again: a retry after a crash continues where the previous attempt stopped.
 */
class PrepareCampaignMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public Campaign $campaign)
    {
        $this->onQueue(config('prosend.sending.queue'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("prepare-campaign:{$this->campaign->getKey()}"))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(CampaignDispatcher $dispatcher): void
    {
        $campaign = $this->campaign->refresh();

        $isUnprepared = in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Queued], true)
            || ($campaign->status === CampaignStatus::Sending && $campaign->prepared_at === null);

        if (! $isUnprepared) {
            return;
        }

        $started = Campaign::query()
            ->whereKey($campaign->getKey())
            ->whereIn('status', [CampaignStatus::Scheduled, CampaignStatus::Queued, CampaignStatus::Sending])
            ->update([
                'status' => CampaignStatus::Sending,
                'started_at' => $campaign->started_at ?? now(),
            ]);

        if ($started !== 1) {
            return;
        }

        $campaign->refresh();

        $dispatcher->createMessages($campaign);
        $dispatcher->dispatchPending($campaign);

        $campaign->forceFill(['prepared_at' => now()])->save();
        $campaign->syncCompletionStatus();
    }
}
