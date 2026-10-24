<?php

namespace App\Services\Warmup;

use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Enums\WarmupRecommendation;
use App\Models\EmailEvent;
use App\Models\SendingDomain;
use App\Models\WarmupPlan;
use App\Services\Analytics\EmailMetrics;

/**
 * Recommends what to do next from the last seven days of real outcomes. Opens and clicks are
 * deliberately ignored: privacy proxies and security scanners make them unreliable.
 */
class WarmupAdvisor
{
    /**
     * Below this many accepted emails, rates are too noisy to justify raising volume.
     */
    public const MINIMUM_SAMPLE = 50;

    /**
     * Pausing needs only a few real problems: a handful of hard bounces or complaints above the plan's
     * rate limit is reason enough to stop and look, however small the sample.
     */
    private const MINIMUM_HARD_BOUNCES_TO_PAUSE = 3;

    private const MINIMUM_COMPLAINTS_TO_PAUSE = 2;

    private const WINDOW_DAYS = 7;

    public function __construct(
        private EmailMetrics $metrics,
        private WarmupGate $gate,
    ) {}

    public function assess(WarmupPlan $plan): WarmupAssessment
    {
        $filters = $plan->metricsFilters(self::WINDOW_DAYS);
        $summary = $this->metrics->summary($filters);
        $accepted = $summary['accepted'];
        $rate = fn (int $count): float => $accepted > 0 ? round($count / $accepted * 100, 3) : 0.0;

        // Only temporary rejections reported by mail servers (SMTP 4xx) count as throttling. ProSend's own
        // waits (quota, warm-up) and credential errors do not.
        $deferrals = EmailEvent::query()
            ->whereIn('email_message_id', $this->metrics->messages($filters)->select('email_messages.id'))
            ->where('occurred_at', '>=', now()->subDays(self::WINDOW_DAYS - 1)->startOfDay())
            ->where(fn ($events) => $events
                ->where('type', EmailEventType::SoftBounced)
                ->orWhere(fn ($deferred) => $deferred
                    ->where('type', EmailEventType::Deferred)
                    ->where(fn ($source) => $source->where('source', EventSource::BrevoWebhook)->orWhere('details->smtp_code', 'like', '4%'))))
            ->count();

        $hardBounceRate = $rate($summary['hard_bounces']);
        $complaintRate = $rate($summary['complaints']);
        $sentToday = $this->gate->sentToday($plan);
        $todaysLimit = $plan->todaysLimit();

        [$recommendation, $reasons] = $this->recommend($plan, $accepted, $summary['hard_bounces'], $summary['complaints'], $hardBounceRate, $complaintRate, $deferrals, $todaysLimit);

        return new WarmupAssessment(
            recommendation: $recommendation,
            reasons: $reasons,
            accepted: $accepted,
            hardBounceRate: $hardBounceRate,
            softBounceRate: $rate($summary['soft_bounces']),
            complaintRate: $complaintRate,
            unsubscribeRate: $rate($summary['unsubscribes']),
            deferrals: $deferrals,
            failures: $summary['failed'] + $summary['unknown'],
            sentToday: $sentToday,
            todaysLimit: $todaysLimit,
        );
    }

    /**
     * @return array{0: WarmupRecommendation, 1: list<string>}
     */
    private function recommend(WarmupPlan $plan, int $accepted, int $hardBounces, int $complaints, float $hardBounceRate, float $complaintRate, int $deferrals, int $todaysLimit): array
    {
        // Harm signals come first: no other concern may hide a bounce or complaint problem.
        if ($hardBounces >= self::MINIMUM_HARD_BOUNCES_TO_PAUSE && $hardBounceRate > $plan->max_hard_bounce_rate) {
            return [WarmupRecommendation::InvestigateBounces, ["Hard bounce rate {$hardBounceRate}% is above the plan limit of {$plan->max_hard_bounce_rate}%."]];
        }

        if ($complaints >= self::MINIMUM_COMPLAINTS_TO_PAUSE && $complaintRate > $plan->max_complaint_rate) {
            return [WarmupRecommendation::ReviewListQuality, ["Spam complaint rate {$complaintRate}% is above the plan limit of {$plan->max_complaint_rate}%."]];
        }

        $domain = $plan->sendingDomain ?? SendingDomain::query()->whereBelongsTo($plan->smtpProvider)->first();

        if ($domain !== null && ! $domain->isReady()) {
            return [WarmupRecommendation::ReviewAuthentication, $domain->readinessIssues()];
        }

        if ($accepted < self::MINIMUM_SAMPLE) {
            return [WarmupRecommendation::NotEnoughData, ["Only {$accepted} emails were accepted in the last 7 days; at least ".self::MINIMUM_SAMPLE.' are needed to judge reputation.']];
        }

        if ($deferrals > $accepted * 0.1) {
            return [WarmupRecommendation::ContactProvider, ["{$deferrals} deferrals or soft bounces against {$accepted} accepted emails suggests throttling."]];
        }

        if ($hardBounceRate > $plan->max_hard_bounce_rate * 0.75 || $complaintRate > $plan->max_complaint_rate * 0.75) {
            return [WarmupRecommendation::ReduceVolume, ['Bounce or complaint rates are close to the plan limits.']];
        }

        $usedYesterday = $this->metrics->summary($plan->metricsFilters(1, endingDaysAgo: 1))['accepted'];

        if ($usedYesterday < $todaysLimit * 0.8) {
            return [WarmupRecommendation::HoldVolume, ["Yesterday {$usedYesterday} emails were accepted against a limit of {$todaysLimit}, so there is no evidence the next step is safe yet."]];
        }

        return [WarmupRecommendation::Continue, ["Hard bounces {$hardBounceRate}% and complaints {$complaintRate}% are within the plan limits."]];
    }
}
