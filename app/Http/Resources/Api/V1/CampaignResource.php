<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\SequenceStatus;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'object' => 'campaign',
            'name' => $this->name,
            'status' => $this->status->value,
            'purpose' => $this->purpose->value,
            'subject' => $this->subject,
            'steps' => 1 + (int) ($this->steps_count ?? $this->steps()->count()),
            'audience_type' => $this->audience_type->value,
            'sending_window' => $this->sendingWindowFor()->describe(),
            // Counted in the database, and only for a single campaign.
            'prospects' => $this->when($request->routeIs('api.v1.campaigns.show'), fn (): array => collect(SequenceStatus::cases())
                ->mapWithKeys(fn (SequenceStatus $status): array => [$status->value => 0])
                ->merge($this->prospects()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn (mixed $count): int => (int) $count))
                ->all()),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
