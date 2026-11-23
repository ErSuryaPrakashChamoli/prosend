<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignApprovalNotifier;
use App\Services\Campaigns\CampaignDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Number;

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

    public function handle(CampaignDispatcher $dispatcher, CampaignApprovalNotifier $notifier, AuditLogger $auditLogger): void
    {
        $campaign = $this->campaign->refresh();

        $isWaiting = in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Queued], true);
        $isUnprepared = $isWaiting || ($campaign->status === CampaignStatus::Sending && $campaign->prepared_at === null);

        if (! $isUnprepared) {
            return;
        }

        // A job left over from before a reschedule; the job for the current time is queued separately.
        if ($campaign->status === CampaignStatus::Scheduled && $campaign->scheduled_at?->isFuture()) {
            return;
        }

        // A campaign resumed part-way through preparation has started already, so it is never sent back for approval.
        if ($isWaiting && $campaign->started_at === null && $this->returnForApprovalIfAudienceGrew($campaign, $notifier, $auditLogger)) {
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

    /**
     * Approval covered a number of recipients. When noticeably more are eligible by the time sending starts, such as
     * after an import into a targeted list, the campaign goes back for approval instead of emailing people nobody
     * reviewed. Returns true when the campaign must not be prepared now.
     */
    private function returnForApprovalIfAudienceGrew(Campaign $campaign, CampaignApprovalNotifier $notifier, AuditLogger $auditLogger): bool
    {
        $limit = $campaign->approvedRecipientLimit();

        if ($limit === null) {
            return false;
        }

        $approvedCount = $campaign->approved_recipient_count;
        $eligibleCount = $campaign->eligibleAudienceQuery()->count();

        if ($eligibleCount <= $limit) {
            return false;
        }

        $note = 'Returned for approval when sending was due: '.Number::format($eligibleCount).' recipients are eligible now, but '.Number::format($approvedCount).' were approved.';

        $returned = $campaign->transitionTo(CampaignStatus::PendingApproval, from: [CampaignStatus::Scheduled, CampaignStatus::Queued], attributes: [
            'approved_by' => null,
            'approved_at' => null,
            'approved_recipient_count' => null,
            'review_note' => $note,
        ], onlyBeforeSending: true);

        // Paused or cancelled meanwhile: the other change wins, and nothing is prepared either way.
        if (! $returned) {
            return true;
        }

        $auditLogger->record('campaign.returned_for_approval', $campaign, [
            'approved_recipients' => $approvedCount,
            'eligible_recipients' => $eligibleCount,
        ]);

        $notifier->notifyApprovers($campaign, "Campaign needs approval again: {$campaign->name}", $note, except: $campaign->submitter);
        $notifier->notifySubmitter($campaign, "Campaign needs approval again: {$campaign->name}", $note);

        return true;
    }
}
