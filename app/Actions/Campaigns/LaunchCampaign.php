<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\PrepareCampaignMessages;
use App\Models\Campaign;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignReadiness;
use App\Services\Campaigns\ReadinessReport;
use Illuminate\Validation\ValidationException;

class LaunchCampaign
{
    public function __construct(
        private CampaignReadiness $readiness,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Campaign $campaign): ReadinessReport
    {
        $report = $this->readiness->assess($campaign);

        if (! $report->canLaunch()) {
            throw ValidationException::withMessages(['campaign' => $report->blockingIssues]);
        }

        $isScheduled = $campaign->scheduled_at?->isFuture() ?? false;

        $launched = Campaign::query()
            ->whereKey($campaign->getKey())
            ->where('status', CampaignStatus::Draft)
            ->update(['status' => $isScheduled ? CampaignStatus::Scheduled : CampaignStatus::Queued]);

        if ($launched !== 1) {
            throw ValidationException::withMessages(['campaign' => 'This campaign has already been launched.']);
        }

        $campaign->refresh();

        PrepareCampaignMessages::dispatch($campaign)->delay($isScheduled ? $campaign->scheduled_at : null);

        $this->auditLogger->record('campaign.launched', $campaign, [
            'eligible_recipients' => $report->eligibleCount,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
        ]);

        return $report;
    }
}
