<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'object' => 'conversation',
            'subject' => $this->subject,
            'status' => $this->status->value,
            'prospect' => $this->contact === null ? null : ['id' => $this->contact->getKey(), 'email' => $this->contact->email],
            'campaign_id' => $this->campaign_id,
            'assigned_to' => $this->assignee?->email,
            'is_read' => $this->is_read,
            'is_starred' => $this->is_starred,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn (ConversationMessage $message): array => [
                'direction' => $message->direction,
                'from' => $message->from_email,
                'subject' => $message->subject,
                'text' => $message->text_body,
                'automatic_reply' => $message->is_auto_reply,
                'attachments' => array_map(fn (array $attachment): array => ['name' => $attachment['name'], 'size' => $attachment['size']], $message->attachments ?? []),
                'at' => $message->received_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
