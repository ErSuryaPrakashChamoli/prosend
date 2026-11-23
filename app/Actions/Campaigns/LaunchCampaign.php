<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\PrepareCampaignMessages;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignApprovalNotifier;
use App\Services\Campaigns\CampaignReadiness;
use App\Services\Campaigns\ReadinessReport;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * The second half of sending a campaign: an administrator approves it, which records the approved audience
 * size and queues it. Nothing is sent in the request itself.
 */
class LaunchCampaign
{
    public function __construct(
        private CampaignReadiness $readiness,
        private CampaignApprovalNotifier $notifier,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * The panel passes what the approver reviewed: the campaign's submission fingerprint and the eligible count it
     * showed. Approval is refused if the campaign changed since, and the reviewed count becomes the approved count.
     *
     * @throws ValidationException
     */
    public function handle(Campaign $campaign, User $approver, ?string $reviewedFingerprint = null, ?int $reviewedEligibleCount = null): ReadinessReport
    {
        if ($campaign->status !== CampaignStatus::PendingApproval) {
            throw ValidationException::withMessages(['campaign' => 'Only campaigns waiting for approval can be launched.']);
        }

        if (! $approver->can('approve', $campaign)) {
            throw ValidationException::withMessages(['campaign' => config('prosend.campaigns.approval.require_second_approver')
                ? 'An administrator who did not create or submit this campaign must approve it.'
                : 'Only an administrator can approve this campaign.']);
        }

        if ($reviewedFingerprint !== null && ! hash_equals($campaign->submissionFingerprint(), $reviewedFingerprint)) {
            throw ValidationException::withMessages(['campaign' => 'The campaign changed after you opened this review. Review it again before approving.']);
        }

        $report = $this->readiness->assess($campaign);

        if (! $report->canLaunch()) {
            throw ValidationException::withMessages(['campaign' => $report->blockingIssues]);
        }

        $approvedCount = $reviewedEligibleCount ?? $report->eligibleCount;

        if ($report->eligibleCount > Campaign::recipientLimitFor($approvedCount)) {
            throw ValidationException::withMessages(['campaign' => 'The audience grew from '.Number::format($approvedCount).' to '.Number::format($report->eligibleCount).' eligible recipients since you opened this review. Review it again before approving.']);
        }

        $isScheduled = $campaign->scheduled_at?->isFuture() ?? false;

        $launched = $campaign->transitionTo($isScheduled ? CampaignStatus::Scheduled : CampaignStatus::Queued, from: [CampaignStatus::PendingApproval], attributes: [
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
            'approved_recipient_count' => $approvedCount,
            'review_note' => null,
        ], onlyBeforeSending: true);

        if (! $launched) {
            throw ValidationException::withMessages(['campaign' => 'This campaign has already been launched.']);
        }

        PrepareCampaignMessages::dispatch($campaign)->delay($isScheduled ? $campaign->scheduled_at : null);

        $this->auditLogger->record('campaign.launched', $campaign, [
            'eligible_recipients' => $approvedCount,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
        ], $approver);

        $this->notifier->notifySubmitter(
            $campaign,
            "Campaign approved: {$campaign->name}",
            "{$approver->name} approved it for ".Number::format($approvedCount).' '.str('recipient')->plural($approvedCount).'.',
            except: $approver,
        );

        return $report;
    }
}
