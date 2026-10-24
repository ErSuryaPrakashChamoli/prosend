<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EmailEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailEvent
 */
class EmailEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'object' => 'email_event',
            'type' => $this->type->value,
            'source' => $this->source->value,
            'recipient' => $this->recipient_email,
            'details' => (object) ($this->details ?? []),
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
