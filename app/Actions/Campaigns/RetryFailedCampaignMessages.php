<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Models\Campaign;
use App\Models\EmailMessage;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignDispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RetryFailedCampaignMessages
{
    public function __construct(
        private CampaignDispatcher $dispatcher,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Retry failures caused by temporary or configuration problems. Rejected addresses and messages
     * whose outcome is unknown are never retried in bulk.
     *
     * @throws ValidationException
     */
    public function handle(Campaign $campaign): int
    {
        $finished = [CampaignStatus::Completed, CampaignStatus::PartiallyFailed, CampaignStatus::Failed];

        if (! in_array($campaign->status, $finished, true)) {
            throw ValidationException::withMessages(['campaign' => 'Failures can be retried once the campaign has finished sending.']);
        }

        $retried = 0;

        $campaign->messages()
            ->where('status', MessageStatus::Failed)
            ->where('is_retryable', true)
            ->chunkById(500, function (Collection $messages) use (&$retried): void {
                $messages->each(fn (EmailMessage $message) => $message->resetForRetry());
                $retried += $messages->count();
            });

        if ($retried === 0) {
            throw ValidationException::withMessages(['campaign' => 'There are no failures that can be retried. Permanent failures, such as rejected addresses, are never retried.']);
        }

        $campaign->transitionTo(CampaignStatus::Sending, from: $finished, attributes: ['completed_at' => null]);

        $this->dispatcher->dispatchPending($campaign);

        $this->auditLogger->record('campaign.failures_retried', $campaign, ['messages' => $retried]);

        return $retried;
    }
}
