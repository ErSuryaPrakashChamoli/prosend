<?php

namespace App\Services\Warmup;

use App\Enums\WarmupRecommendation;
use App\Enums\WarmupStatus;
use App\Models\WarmupPlan;
use App\Services\AuditLogger;

/**
 * Applies the advisor's recommendation once a day. Volume only ever goes up when signals are
 * healthy, the previous limit was actually used, and the ramp interval has passed.
 */
class WarmupProgression
{
    public function __construct(
        private WarmupAdvisor $advisor,
        private WarmupScheduler $scheduler,
        private AuditLogger $auditLogger,
    ) {}

    public function evaluate(WarmupPlan $plan): WarmupAssessment
    {
        $assessment = $this->advisor->assess($plan);
        $current = $plan->todaysLimit();
        $changes = ['last_evaluated_at' => now(), 'last_recommendation' => $assessment->recommendation];

        if ($assessment->requiresPause() && in_array($plan->status, [WarmupStatus::Active, WarmupStatus::Completed], true)) {
            $changes = [...$changes, 'status' => WarmupStatus::Paused, 'paused_reason' => implode(' ', $assessment->reasons)];
        } elseif ($plan->status === WarmupStatus::Active) {
            $changes = [...$changes, ...$this->changesFor($plan, $assessment, $current)];
        }

        $plan->forceFill($changes)->save();

        if (isset($changes['status']) || ($changes['current_daily_volume'] ?? $current) !== $current) {
            $this->auditLogger->record('warmup.evaluated', $plan, [
                'recommendation' => $assessment->recommendation->value,
                'daily_volume_from' => $current,
                'daily_volume_to' => $plan->todaysLimit(),
                'status' => $plan->status->value,
            ]);
        }

        return $assessment;
    }

    /**
     * @return array<string, mixed>
     */
    private function changesFor(WarmupPlan $plan, WarmupAssessment $assessment, int $current): array
    {
        if ($assessment->recommendation === WarmupRecommendation::ReduceVolume) {
            return ['current_daily_volume' => max($plan->initial_daily_volume, (int) floor($current * 0.75)), 'last_increased_at' => now()];
        }

        $intervalPassed = $plan->last_increased_at === null
            || $plan->last_increased_at->lte(now()->subDays($plan->ramp_interval_days)->addMinutes(5));

        if ($assessment->recommendation !== WarmupRecommendation::Continue || ! $intervalPassed || ! $this->scheduler->isSendingDay($plan, now())) {
            return [];
        }

        $next = $this->scheduler->nextVolume($plan, $current);

        return [
            'current_daily_volume' => $next,
            'last_increased_at' => now(),
            ...($next >= $plan->ceiling() ? ['status' => WarmupStatus::Completed] : []),
        ];
    }
}
