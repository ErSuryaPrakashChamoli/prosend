<?php

namespace App\Actions\Messages;

use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Jobs\SendEmailMessage;
use App\Models\EmailMessage;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

class RetryEmailMessage
{
    public function __construct(private AuditLogger $auditLogger) {}

    public static function canRetry(EmailMessage $message): bool
    {
        if ($message->campaign?->status === CampaignStatus::Cancelled || $message->contentWasPruned()) {
            return false;
        }

        return $message->status === MessageStatus::Unknown
            || ($message->status === MessageStatus::Failed && $message->is_retryable);
    }

    /**
     * @throws ValidationException
     */
    public function handle(EmailMessage $message): void
    {
        if ($message->contentWasPruned()) {
            throw ValidationException::withMessages(['message' => 'The content of this email was deleted under the data retention policy, so it cannot be sent again.']);
        }

        if (! static::canRetry($message)) {
            throw ValidationException::withMessages(['message' => 'Only temporary failures and messages with an unknown outcome can be retried.']);
        }

        $previousStatus = $message->status;

        $message->resetForRetry();

        $campaign = $message->campaign;

        if ($campaign !== null) {
            $campaign->transitionTo(CampaignStatus::Sending, from: [CampaignStatus::Completed, CampaignStatus::PartiallyFailed, CampaignStatus::Failed], attributes: ['completed_at' => null]);
        }

        SendEmailMessage::dispatch($message);

        $this->auditLogger->record('email.retried', $message, ['previous_status' => $previousStatus->value]);
    }
}
