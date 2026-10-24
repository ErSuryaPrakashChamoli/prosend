<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignDispatcher;
use Illuminate\Validation\ValidationException;

class PauseCampaign
{
    public function __construct(
        private CampaignDispatcher $dispatcher,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Stop sending. Messages already handed to the provider are unaffected; the rest stay queued.
     *
     * @throws ValidationException
     */
    public function handle(Campaign $campaign): void
    {
        $pausable = array_values(array_filter(CampaignStatus::cases(), fn (CampaignStatus $status): bool => $status->canBePaused()));

        if (! $campaign->transitionTo(CampaignStatus::Paused, from: $pausable)) {
            throw ValidationException::withMessages(['campaign' => 'Only scheduled, queued, or sending campaigns can be paused.']);
        }

        $this->dispatcher->cancelBatch($campaign);

        $this->auditLogger->record('campaign.paused', $campaign);
    }
}
