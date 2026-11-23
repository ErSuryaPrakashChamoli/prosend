<?php

namespace App\Models;

use Database\Factories\CampaignVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An alternative version (B, C, …) of a campaign's first email or of one follow-up, for A/B testing. Version A is the
 * campaign's or step's own content. weight is this version's percentage of the traffic; A gets what is left.
 * A version without a subject uses the original's subject.
 */
#[Fillable(['campaign_id', 'campaign_step_id', 'position', 'weight', 'subject', 'body', 'text_body', 'is_active'])]
class CampaignVariant extends Model
{
    /** @use HasFactory<CampaignVariantFactory> */
    use HasFactory;

    public const MAX_VARIANTS = 4;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'weight' => 50,
        'is_active' => true,
    ];

    /**
     * A follow-up's versions are saved through the step, so they take the step's campaign.
     */
    protected static function booted(): void
    {
        static::saving(function (CampaignVariant $variant): void {
            if ($variant->campaign_step_id !== null) {
                $variant->campaign_id = (int) CampaignStep::query()->whereKey($variant->campaign_step_id)->value('campaign_id');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'weight' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<CampaignStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class, 'campaign_step_id');
    }

    /**
     * B for the first alternative, C for the second, and so on.
     */
    public function label(): string
    {
        return chr(ord('A') + max(1, $this->position));
    }
}
