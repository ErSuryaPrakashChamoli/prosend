<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Models\Campaign;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignDispatcher;
use Illuminate\Validation\ValidationException;

class CancelCampaign
{
    public function __construct(
        private CampaignDispatcher $dispatcher,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Campaign $campaign): int
    {
        $cancellable = array_values(array_filter(CampaignStatus::cases(), fn (CampaignStatus $status): bool => $status->canBeCancelled()));

        if (! $campaign->transitionTo(CampaignStatus::Cancelled, from: $cancellable, attributes: ['completed_at' => now()])) {
            throw ValidationException::withMessages(['campaign' => 'This campaign can no longer be cancelled.']);
        }

        $this->dispatcher->cancelBatch($campaign);

        $cancelled = $campaign->messages()
            ->whereIn('status', [MessageStatus::Queued, MessageStatus::Deferred])
            ->update(['status' => MessageStatus::Cancelled, 'updated_at' => now()]);

        $this->auditLogger->record('campaign.cancelled', $campaign, ['unsent_messages' => $cancelled]);

        return $cancelled;
    }
}
