<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignApprovalNotifier;
use App\Services\Campaigns\CampaignReadiness;
use App\Services\Campaigns\ReadinessReport;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * The first half of sending a campaign: it is checked, locked against edits, and waits for an administrator.
 */
class SubmitCampaignForApproval
{
    public function __construct(
        private CampaignReadiness $readiness,
        private CampaignApprovalNotifier $notifier,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Campaign $campaign, User $submitter): ReadinessReport
    {
        if (! $submitter->can('submit', $campaign)) {
            throw ValidationException::withMessages(['campaign' => 'Only draft campaigns can be submitted, by someone who can send email.']);
        }

        $report = $this->readiness->assess($campaign);

        if (! $report->canLaunch()) {
            throw ValidationException::withMessages(['campaign' => $report->blockingIssues]);
        }

        $submitted = $campaign->transitionTo(CampaignStatus::PendingApproval, from: [CampaignStatus::Draft], attributes: [
            'submitted_by' => $submitter->getKey(),
            'submitted_at' => now(),
            'approved_by' => null,
            'approved_at' => null,
            'approved_recipient_count' => null,
            'review_note' => null,
        ], onlyBeforeSending: true);

        if (! $submitted) {
            throw ValidationException::withMessages(['campaign' => 'This campaign has already been submitted.']);
        }

        $this->auditLogger->record('campaign.submitted', $campaign, ['eligible_recipients' => $report->eligibleCount], $submitter);

        $this->notifier->notifyApprovers(
            $campaign,
            "Campaign waiting for approval: {$campaign->name}",
            "{$submitter->name} submitted it to send to ".Number::format($report->eligibleCount).' '.str('recipient')->plural($report->eligibleCount).'.',
            except: $submitter,
        );

        return $report;
    }
}
