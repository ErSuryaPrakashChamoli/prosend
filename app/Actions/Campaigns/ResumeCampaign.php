<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\PrepareCampaignMessages;
use App\Models\Campaign;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignDispatcher;
use Illuminate\Validation\ValidationException;

class ResumeCampaign
{
    public function __construct(
        private CampaignDispatcher $dispatcher,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Continue a paused campaign. One that was never fully prepared is prepared again, which picks up
     * where preparation stopped.
     *
     * @throws ValidationException
     */
    public function handle(Campaign $campaign): void
    {
        if ($campaign->prepared_at === null) {
            $isScheduled = $campaign->scheduled_at?->isFuture() ?? false;

            if (! $campaign->transitionTo($isScheduled ? CampaignStatus::Scheduled : CampaignStatus::Queued, from: [CampaignStatus::Paused])) {
                throw ValidationException::withMessages(['campaign' => 'Only paused campaigns can be resumed.']);
            }

            PrepareCampaignMessages::dispatch($campaign)->delay($isScheduled ? $campaign->scheduled_at : null);
        } else {
            if (! $campaign->transitionTo(CampaignStatus::Sending, from: [CampaignStatus::Paused])) {
                throw ValidationException::withMessages(['campaign' => 'Only paused campaigns can be resumed.']);
            }

            $this->dispatcher->dispatchPending($campaign);

            $campaign->syncCompletionStatus();
        }

        $this->auditLogger->record('campaign.resumed', $campaign);
    }
}
