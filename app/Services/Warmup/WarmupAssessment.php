<?php

namespace App\Services\Warmup;

use App\Enums\WarmupRecommendation;

final readonly class WarmupAssessment
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public WarmupRecommendation $recommendation,
        public array $reasons,
        public int $accepted,
        public float $hardBounceRate,
        public float $softBounceRate,
        public float $complaintRate,
        public float $unsubscribeRate,
        public int $deferrals,
        public int $failures,
        public int $sentToday,
        public int $todaysLimit,
    ) {}

    /**
     * Whether signals are bad enough that sending under the plan must stop.
     */
    public function requiresPause(): bool
    {
        return in_array($this->recommendation, [WarmupRecommendation::InvestigateBounces, WarmupRecommendation::ReviewListQuality], true);
    }
}
