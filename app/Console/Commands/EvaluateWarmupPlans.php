<?php

namespace App\Console\Commands;

use App\Enums\WarmupStatus;
use App\Models\WarmupPlan;
use App\Services\Warmup\WarmupProgression;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('prosend:evaluate-warmups')]
#[Description('Review each active warm-up plan against the last 7 days of results and adjust its daily volume')]
class EvaluateWarmupPlans extends Command
{
    public function handle(WarmupProgression $progression): int
    {
        WarmupPlan::query()
            ->whereIn('status', WarmupStatus::enforcing())
            ->each(function (WarmupPlan $plan) use ($progression): void {
                $assessment = $progression->evaluate($plan);

                $this->components->twoColumnDetail($plan->name, "{$assessment->recommendation->getLabel()} · limit {$plan->refresh()->todaysLimit()}/day");
            });

        return self::SUCCESS;
    }
}
