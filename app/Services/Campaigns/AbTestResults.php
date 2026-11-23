<?php

namespace App\Services\Campaigns;

use App\Enums\EmailEventType;
use App\Enums\MessageStatus;
use App\Models\Campaign;
use App\Models\CampaignVariant;
use App\Models\EmailEvent;

/**
 * How each version of each A/B-tested email performed, and whether one is clearly better.
 *
 * Replies decide, because they are what outreach is for and, unlike opens, are not distorted by privacy features. When
 * no version has a reply yet, opens decide instead and the result says so. Stopped versions are left out. A winner is
 * only named when every remaining version was sent at least MIN_SAMPLE times and the best one beats each other version
 * in a two-proportion z-test at 95% confidence, with the threshold raised (Bonferroni) when several versions are
 * compared. Looking at results repeatedly still raises the chance of a false winner, so the verdict says so.
 */
class AbTestResults
{
    public const MIN_SAMPLE = 100;

    public const CONFIDENCE_Z = 1.96;

    /**
     * @return list<array{step: int, metric: string, verdict: string, winner: ?string, versions: list<array{label: string, subject: string, weight: int, active: bool, sent: int, opened: int, clicked: int, replied: int, bounced: int, open_rate: float, click_rate: float, reply_rate: float}>}>
     */
    public function for(Campaign $campaign): array
    {
        $variants = $campaign->variants()->get()->groupBy(fn (CampaignVariant $variant): int => $variant->campaign_step_id === null ? 1 : $variant->step->stepNumber());

        if ($variants->isEmpty()) {
            return [];
        }

        $sent = $this->sentCounts($campaign);
        $events = $this->eventCounts($campaign);
        $steps = $campaign->steps->keyBy(fn ($step): int => $step->stepNumber());
        $results = [];

        foreach ($variants->sortKeys() as $stepNumber => $stepVariants) {
            $versions = [$this->version('A', $stepNumber === 1 ? (string) $campaign->subject : (string) ($steps[$stepNumber]->subject ?? ''), 100 - (int) $stepVariants->where('is_active', true)->sum('weight'), true, $sent, $events, $stepNumber, 0)];

            foreach ($stepVariants->sortBy('position') as $variant) {
                $versions[] = $this->version($variant->label(), (string) $variant->subject, $variant->weight, $variant->is_active, $sent, $events, $stepNumber, $variant->getKey());
            }

            $results[] = ['step' => $stepNumber, ...$this->verdict($versions), 'versions' => $versions];
        }

        return $results;
    }

    /**
     * @param  array<string, int>  $sent
     * @param  array<string, array<string, int>>  $events
     * @return array{label: string, subject: string, weight: int, active: bool, sent: int, opened: int, clicked: int, replied: int, bounced: int, open_rate: float, click_rate: float, reply_rate: float}
     */
    private function version(string $label, string $subject, int $weight, bool $active, array $sent, array $events, int $step, int $variantId): array
    {
        $key = "{$step}:{$variantId}";
        $count = $sent[$key] ?? 0;
        $opened = $events[$key][EmailEventType::Opened->value] ?? 0;
        $clicked = $events[$key][EmailEventType::Clicked->value] ?? 0;
        $replied = $events[$key][EmailEventType::Replied->value] ?? 0;

        return [
            'label' => $label,
            'subject' => $subject,
            'weight' => max(0, $weight),
            'active' => $active,
            'sent' => $count,
            'opened' => $opened,
            'clicked' => $clicked,
            'replied' => $replied,
            'bounced' => $events[$key][EmailEventType::Bounced->value] ?? 0,
            'open_rate' => $count > 0 ? round($opened / $count * 100, 1) : 0.0,
            'click_rate' => $count > 0 ? round($clicked / $count * 100, 1) : 0.0,
            'reply_rate' => $count > 0 ? round($replied / $count * 100, 1) : 0.0,
        ];
    }

    /**
     * @param  list<array{label: string, sent: int, opened: int, replied: int}>  $versions
     * @return array{metric: string, verdict: string, winner: ?string}
     */
    private function verdict(array $versions): array
    {
        $versions = array_values(array_filter($versions, fn (array $version): bool => $version['active']));

        if (count($versions) < 2) {
            return ['metric' => 'reply rate', 'verdict' => 'Only one version is still being sent.', 'winner' => null];
        }

        $metric = array_sum(array_column($versions, 'replied')) > 0 ? 'replied' : 'opened';
        $metricLabel = $metric === 'replied' ? 'reply rate' : 'open rate (no replies yet)';

        if (min(array_column($versions, 'sent')) < self::MIN_SAMPLE) {
            return ['metric' => $metricLabel, 'verdict' => 'Still collecting data: each version needs '.self::MIN_SAMPLE.' sent emails before a winner can be named.', 'winner' => null];
        }

        usort($versions, fn (array $a, array $b): int => $b[$metric] / $b['sent'] <=> $a[$metric] / $a['sent']);
        $best = $versions[0];

        $threshold = self::threshold(count($versions) - 1);

        foreach (array_slice($versions, 1) as $other) {
            if ($this->zScore($best[$metric], $best['sent'], $other[$metric], $other['sent']) < $threshold) {
                return ['metric' => $metricLabel, 'verdict' => "No clear winner yet: version {$best['label']} leads, but the difference could be chance.", 'winner' => null];
            }
        }

        return ['metric' => $metricLabel, 'verdict' => "Version {$best['label']} wins on {$metricLabel}, with 95% confidence. Decide on the sample size in advance: checking repeatedly makes a chance winner more likely.", 'winner' => $best['label']];
    }

    /**
     * The z score needed for 95% confidence across this many comparisons (one-sided, Bonferroni-corrected).
     */
    public static function threshold(int $comparisons): float
    {
        return match (true) {
            $comparisons <= 1 => self::CONFIDENCE_Z,
            $comparisons === 2 => 2.241,
            $comparisons === 3 => 2.394,
            default => 2.498,
        };
    }

    /**
     * Two-proportion z-test statistic for "rate 1 is higher than rate 2", using the pooled proportion.
     */
    public function zScore(int $successes1, int $trials1, int $successes2, int $trials2): float
    {
        if ($trials1 === 0 || $trials2 === 0) {
            return 0.0;
        }

        $pooled = ($successes1 + $successes2) / ($trials1 + $trials2);
        $standardError = sqrt($pooled * (1 - $pooled) * (1 / $trials1 + 1 / $trials2));

        return $standardError > 0 ? (($successes1 / $trials1) - ($successes2 / $trials2)) / $standardError : 0.0;
    }

    /**
     * Emails each version reached the provider (or sandbox) with, by "step:variant id" (0 for version A).
     *
     * @return array<string, int>
     */
    private function sentCounts(Campaign $campaign): array
    {
        return $campaign->messages()
            ->whereIn('status', [...MessageStatus::acceptedByProvider(), MessageStatus::Captured])
            ->toBase()
            ->selectRaw('step_number, coalesce(campaign_variant_id, 0) as variant, count(*) as aggregate')
            ->groupBy('step_number', 'variant')
            ->get()
            ->mapWithKeys(fn (object $row): array => ["{$row->step_number}:{$row->variant}" => (int) $row->aggregate])
            ->all();
    }

    /**
     * Emails with at least one open, click, reply or hard bounce, by "step:variant id" and event type.
     *
     * @return array<string, array<string, int>>
     */
    private function eventCounts(Campaign $campaign): array
    {
        $counts = [];

        EmailEvent::query()
            ->join('email_messages', 'email_messages.id', '=', 'email_events.email_message_id')
            ->where('email_messages.campaign_id', $campaign->getKey())
            ->where('email_messages.client_id', $campaign->client_id)
            ->whereIn('email_events.type', [EmailEventType::Opened, EmailEventType::Clicked, EmailEventType::Replied, EmailEventType::Bounced])
            ->toBase()
            ->selectRaw('email_messages.step_number, coalesce(email_messages.campaign_variant_id, 0) as variant, email_events.type, count(distinct email_events.email_message_id) as aggregate')
            ->groupBy('email_messages.step_number', 'variant', 'email_events.type')
            ->get()
            ->each(function (object $row) use (&$counts): void {
                $counts["{$row->step_number}:{$row->variant}"][$row->type] = (int) $row->aggregate;
            });

        return $counts;
    }
}
