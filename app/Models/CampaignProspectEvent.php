<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The sequence execution log: enrolled, step queued, step sent, step skipped, stopped, completed.
 */
#[Fillable(['type', 'step_number', 'details'])]
class CampaignProspectEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<CampaignProspect, $this>
     */
    public function campaignProspect(): BelongsTo
    {
        return $this->belongsTo(CampaignProspect::class);
    }

    public function label(): string
    {
        return match ($this->type) {
            'queued' => "Step {$this->step_number} queued",
            'sent' => "Step {$this->step_number} sent",
            'skipped' => "Step {$this->step_number} skipped",
            'stopped' => 'Sequence stopped',
            'completed' => 'Sequence finished',
            default => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }
}
