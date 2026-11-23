<?php

namespace App\Models;

use App\Enums\StepCondition;
use Database\Factories\CampaignStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A follow-up email in a campaign's sequence. The campaign's own content is step 1; position is the follow-up's order
 * (1 for the first follow-up), so its emails are step position + 1. Without a subject of its own, a step replies in
 * the first email's thread ("Re: …").
 */
#[Fillable(['position', 'wait_days', 'condition', 'subject', 'body', 'text_body'])]
class CampaignStep extends Model
{
    /** @use HasFactory<CampaignStepFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'wait_days' => 2,
        'condition' => 'no_reply',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'wait_days' => 'integer',
            'condition' => StepCondition::class,
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
     * Alternative versions (B, C, …) of this follow-up, for A/B testing.
     *
     * @return HasMany<CampaignVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(CampaignVariant::class)->orderBy('position');
    }

    public function stepNumber(): int
    {
        return $this->position + 1;
    }

    public function repliesInThread(): bool
    {
        return blank($this->subject);
    }

    /**
     * The subject before merge tags are filled: the version's own, the step's own, or a reply to the first email the
     * prospect received (which may itself have been an A/B version).
     */
    public function subjectFor(Campaign $campaign, ?CampaignVariant $variant = null, ?string $firstSubject = null): string
    {
        if (filled($variant?->subject)) {
            return (string) $variant->subject;
        }

        return $this->repliesInThread() ? 'Re: '.($firstSubject ?? $campaign->subject) : (string) $this->subject;
    }
}
