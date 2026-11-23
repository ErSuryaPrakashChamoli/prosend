<?php

namespace App\Services\Deliverability;

use App\Enums\BounceType;
use App\Enums\DnsCheckStatus;
use App\Enums\MessageStatus;
use App\Enums\ProviderHealth;
use App\Models\SenderIdentity;
use App\Models\SendingDomain;

/**
 * A 0–100 health score for a sending address, from what ProSend can measure: domain authentication (SPF, DKIM, DMARC,
 * provider verification), the last 30 days' hard-bounce and complaint rates, and the connection's health. It describes
 * risk; it does not predict inbox placement, which only mailbox providers decide.
 */
class DeliverabilityScore
{
    public const MIN_SAMPLE = 50;

    /**
     * @return array{score: int, grade: string, reasons: list<string>}
     */
    public function for(SenderIdentity $identity): array
    {
        $score = 0;
        $reasons = [];
        $domain = SendingDomain::query()->where('domain', $identity->domain())->first();

        if ($domain === null) {
            $reasons[] = "{$identity->domain()} is not under Domain authentication, so SPF, DKIM and DMARC are not checked.";
        } else {
            foreach (['spf' => 10, 'dkim' => 15, 'dmarc' => 10] as $record => $points) {
                if ($domain->{"{$record}_status"} === DnsCheckStatus::Found) {
                    $score += $points;
                } else {
                    $reasons[] = strtoupper($record).' is '.$domain->{"{$record}_status"}->value.'.';
                }
            }

            $domain->isReady() ? $score += 10 : $reasons[] = 'The domain is not ready (provider verification, DKIM and DMARC).';
        }

        $recent = $identity->emailMessages()
            ->where('accepted_at', '>=', now()->subDays(30))
            ->toBase()
            ->selectRaw('count(*) as sent')
            ->selectRaw('sum(case when bounce_type = ? then 1 else 0 end) as bounced', [BounceType::Hard->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as complained', [MessageStatus::Complained->value])
            ->first();
        $sent = (int) $recent->sent;

        if ($sent < self::MIN_SAMPLE) {
            // Too little sending to judge; it neither earns nor loses these points.
            $score += 35;
            $reasons[] = "Only {$sent} emails in 30 days: bounce and complaint rates are not judged yet.";
        } else {
            $bounceRate = (int) $recent->bounced / $sent * 100;
            $complaintRate = (int) $recent->complained / $sent * 100;

            $score += match (true) {
                $bounceRate < 2 => 25,
                $bounceRate < 5 => 10,
                default => 0,
            };

            if ($bounceRate >= 2) {
                $reasons[] = 'Hard bounce rate '.round($bounceRate, 1).'% (keep it under 2%: clean the list).';
            }

            $complaintRate < 0.1 ? $score += 10 : $reasons[] = 'Spam complaint rate '.round($complaintRate, 2).'% (keep it under 0.1%).';
        }

        $healthy = $identity->smtpProvider?->health_status === ProviderHealth::Healthy;
        $healthy ? $score += 10 : $reasons[] = 'The mailbox connection is not confirmed healthy.';
        if ($identity->isVerified()) {
            $score += 10;
        } else {
            $reasons[] = 'The sender is not verified.';
        }

        $score = min(100, $score);

        return [
            'score' => $score,
            'grade' => match (true) {
                $score >= 80 => 'Good',
                $score >= 50 => 'Needs attention',
                default => 'At risk',
            },
            'reasons' => $reasons,
        ];
    }
}
