<?php

use App\Models\SmtpProvider;
use App\Models\WarmupPlan;
use App\Services\Warmup\WarmupScheduler;

it('grows volume step by step and stops at the target', function () {
    $plan = WarmupPlan::factory()->make(['start_date' => '2026-10-26', 'initial_daily_volume' => 100, 'target_daily_volume' => 200, 'growth_percent' => 30, 'ramp_interval_days' => 2]);
    $plan->setRelation('smtpProvider', SmtpProvider::factory()->make(['daily_limit' => null]));

    $schedule = app(WarmupScheduler::class)->schedule($plan);

    expect(array_map(fn (array $step): array => [$step['date']->toDateString(), $step['daily_volume']], $schedule))->toBe([
        ['2026-10-26', 100],
        ['2026-10-28', 130],
        ['2026-10-30', 169],
        ['2026-11-01', 200],
    ]);
});

it('never plans above the provider daily limit', function () {
    $plan = WarmupPlan::factory()->make(['initial_daily_volume' => 100, 'target_daily_volume' => 5000]);
    $plan->setRelation('smtpProvider', SmtpProvider::factory()->make(['daily_limit' => 300]));

    $schedule = app(WarmupScheduler::class)->schedule($plan);

    expect(max(array_column($schedule, 'daily_volume')))->toBe(300);
});

it('skips weekends when sending on weekdays only', function () {
    $plan = WarmupPlan::factory()->make(['start_date' => '2026-10-24', 'business_days_only' => true, 'initial_daily_volume' => 10, 'target_daily_volume' => 14]);
    $plan->setRelation('smtpProvider', SmtpProvider::factory()->make(['daily_limit' => null]));

    $dates = array_map(fn (array $step): string => $step['date']->toDateString(), app(WarmupScheduler::class)->schedule($plan));

    expect($dates)->toBe(['2026-10-26', '2026-10-27', '2026-10-28']);
});
