<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ProspectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'object' => 'prospect',
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company' => $this->company,
            'job_title' => $this->job_title,
            'industry' => $this->industry,
            'website' => $this->website,
            'phone' => $this->phone,
            'timezone' => $this->timezone,
            'stage' => $this->stage?->value,
            'tags' => $this->tags ?? [],
            'custom_attributes' => (object) ($this->custom_attributes ?? []),
            'consent' => [
                'status' => $this->consent_status?->value,
                'source' => $this->consent_source,
                'given_at' => $this->consented_at?->toIso8601String(),
            ],
            'unsubscribed_at' => $this->unsubscribed_at?->toIso8601String(),
            'last_contacted_at' => $this->last_contacted_at?->toIso8601String(),
            'last_replied_at' => $this->last_replied_at?->toIso8601String(),
            'list_ids' => $this->whenLoaded('lists', fn () => $this->lists->modelKeys()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
