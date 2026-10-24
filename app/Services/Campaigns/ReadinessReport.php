<?php

namespace App\Services\Campaigns;

final readonly class ReadinessReport
{
    /**
     * @param  list<string>  $blockingIssues
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $audienceCount,
        public int $eligibleCount,
        public int $suppressedCount,
        public int $unsubscribedCount,
        public int $withoutConsentCount,
        public int $duplicateCount,
        public int $missingDataCount,
        public ?int $remainingQuota,
        public int $estimatedMinutes,
        public array $blockingIssues,
        public array $warnings,
    ) {}

    public function canLaunch(): bool
    {
        return $this->blockingIssues === [];
    }

    public function excludedCount(): int
    {
        return $this->audienceCount - $this->eligibleCount;
    }
}
