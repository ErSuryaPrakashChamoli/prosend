<?php

namespace App\Services\Warmup;

use App\Models\WarmupPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Proposes a gradual schedule. It is a plan, not a promise: the real volume only increases when
 * actual results are healthy, and never above the provider's limit.
 */
class WarmupScheduler
{
    private const MAX_STEPS = 120;

    /**
     * @return list<array{step: int, date: CarbonInterface, daily_volume: int}>
     */
    public function schedule(WarmupPlan $plan): array
    {
        $steps = [];
        $volume = min($plan->initial_daily_volume, $plan->ceiling());
        $date = $this->nextSendingDay($plan, Carbon::parse($plan->start_date)->startOfDay(), includeToday: true);

        for ($step = 1; $step <= self::MAX_STEPS; $step++) {
            $steps[] = ['step' => $step, 'date' => $date->copy(), 'daily_volume' => $volume];

            if ($volume >= $plan->ceiling()) {
                break;
            }

            $volume = $this->nextVolume($plan, $volume);

            for ($day = 0; $day < $plan->ramp_interval_days; $day++) {
                $date = $this->nextSendingDay($plan, $date->copy()->addDay(), includeToday: true);
            }
        }

        return $steps;
    }

    public function nextVolume(WarmupPlan $plan, int $current): int
    {
        return min($plan->ceiling(), max($current + 1, (int) ceil($current * (1 + $plan->growth_percent / 100))));
    }

    public function isSendingDay(WarmupPlan $plan, CarbonInterface $day): bool
    {
        return ! $plan->business_days_only || $day->isWeekday();
    }

    public function nextSendingDay(WarmupPlan $plan, CarbonInterface $from, bool $includeToday = false): CarbonInterface
    {
        $day = $includeToday ? Carbon::instance($from) : Carbon::instance($from)->addDay();

        while (! $this->isSendingDay($plan, $day)) {
            $day = $day->addDay();
        }

        return $day;
    }
}
