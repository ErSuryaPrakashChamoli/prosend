<?php

namespace App\Models;

use App\Enums\WarmupRecommendation;
use App\Enums\WarmupStatus;
use Database\Factories\WarmupPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['name', 'smtp_provider_id', 'sending_domain_id', 'start_date', 'initial_daily_volume', 'target_daily_volume', 'max_daily_volume', 'max_per_minute', 'ramp_interval_days', 'growth_percent', 'business_days_only', 'sending_window_start', 'sending_window_end', 'max_hard_bounce_rate', 'max_complaint_rate'])]
class WarmupPlan extends Model
{
    /** @use HasFactory<WarmupPlanFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'ramp_interval_days' => 1,
        'growth_percent' => 30,
        'business_days_only' => false,
        'max_hard_bounce_rate' => 2.00,
        'max_complaint_rate' => 0.100,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WarmupStatus::class,
            'start_date' => 'date',
            'initial_daily_volume' => 'integer',
            'target_daily_volume' => 'integer',
            'max_daily_volume' => 'integer',
            'max_per_minute' => 'integer',
            'ramp_interval_days' => 'integer',
            'growth_percent' => 'integer',
            'business_days_only' => 'boolean',
            'max_hard_bounce_rate' => 'float',
            'max_complaint_rate' => 'float',
            'current_daily_volume' => 'integer',
            'last_increased_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'last_recommendation' => WarmupRecommendation::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SmtpProvider, $this>
     */
    public function smtpProvider(): BelongsTo
    {
        return $this->belongsTo(SmtpProvider::class);
    }

    /**
     * @return BelongsTo<SendingDomain, $this>
     */
    public function sendingDomain(): BelongsTo
    {
        return $this->belongsTo(SendingDomain::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The most this plan will ever allow per day: never above the provider's own daily limit.
     */
    public function ceiling(): int
    {
        return min(array_filter(
            [$this->target_daily_volume, $this->max_daily_volume, $this->smtpProvider?->daily_limit],
            fn (?int $limit): bool => $limit !== null,
        ));
    }

    public function todaysLimit(): int
    {
        return min($this->current_daily_volume ?? $this->initial_daily_volume, $this->ceiling());
    }

    /**
     * Whether the plan covers email from the given sender address.
     */
    public function covers(string $senderEmail): bool
    {
        return $this->sendingDomain === null || Str::lower(Str::after($senderEmail, '@')) === $this->sendingDomain->domain;
    }

    /**
     * Filters for EmailMetrics that select the email this plan governs, by the day providers accepted it.
     *
     * @return array<string, mixed>
     */
    public function metricsFilters(int $days, int $endingDaysAgo = 0): array
    {
        return array_filter([
            'smtp_provider_id' => $this->smtp_provider_id,
            'sending_domain' => $this->sendingDomain?->domain,
            'start_date' => now()->subDays($endingDaysAgo + $days - 1)->toDateString(),
            'end_date' => now()->subDays($endingDaysAgo)->toDateString(),
            'period_by' => 'accepted',
        ]);
    }
}
