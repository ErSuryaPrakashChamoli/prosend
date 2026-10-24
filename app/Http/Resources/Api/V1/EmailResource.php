<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\MessageStatus;
use App\Models\EmailMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailMessage
 */
class EmailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasError = filled($this->error_message) && in_array($this->status, [MessageStatus::Failed, MessageStatus::Unknown, MessageStatus::Bounced, MessageStatus::SoftBounced, MessageStatus::Suppressed, MessageStatus::Deferred], true);

        return [
            'id' => $this->uuid,
            'object' => 'email',
            'from' => $this->senderIdentity->email,
            'to' => ['email' => $this->to_email, 'name' => $this->to_name],
            'cc' => $this->cc ?? [],
            'bcc' => $this->bcc ?? [],
            'subject' => $this->subject,
            'purpose' => $this->purpose->value,
            'source' => $this->source?->value,
            'status' => $this->status->value,
            'status_description' => $this->status->getDescription(),
            'template_id' => $this->email_template_id,
            'campaign_id' => $this->campaign_id,
            'tags' => $this->tags ?? [],
            'metadata' => (object) ($this->metadata ?? []),
            'attempts' => $this->attempts,
            'error' => $hasError ? [
                'code' => $this->error_code,
                'message' => $this->error_message,
                'retryable' => $this->is_retryable,
            ] : null,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
