<?php

namespace App\Services\Warmup;

use App\Enums\MessageStatus;
use App\Enums\WarmupStatus;
use App\Models\EmailMessage;
use App\Models\SmtpProvider;
use App\Models\WarmupPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Decides whether a warm-up plan lets an email go out right now.
 */
class WarmupGate
{
    public function __construct(private WarmupScheduler $scheduler) {}

    public function planFor(SmtpProvider $provider, string $senderEmail): ?WarmupPlan
    {
        return WarmupPlan::query()
            ->with(['sendingDomain', 'smtpProvider'])
            ->whereBelongsTo($provider)
            ->whereIn('status', WarmupStatus::enforcing())
            ->get()
            ->first(fn (WarmupPlan $plan): bool => $plan->covers($senderEmail));
    }

    /**
     * When email under the plan may be sent and why it is waiting, or null when it may be sent now.
     *
     * @return array{until: CarbonInterface, reason: string}|null
     */
    public function deferral(WarmupPlan $plan): ?array
    {
        if ($plan->status === WarmupStatus::Paused) {
            return ['until' => now()->addHour(), 'reason' => "The warm-up plan \"{$plan->name}\" is paused."];
        }

        $now = now();
        $windowStart = $this->windowStart($plan, $now);

        if (! $this->scheduler->isSendingDay($plan, $now) || $now->lt($windowStart) || $now->gte($this->windowEnd($plan, $now))) {
            return ['until' => $this->nextWindowStart($plan, $now), 'reason' => "Outside the sending hours of the warm-up plan \"{$plan->name}\"."];
        }

        if ($this->sentToday($plan) >= $plan->todaysLimit()) {
            return ['until' => $this->nextWindowStart($plan, $now), 'reason' => "The warm-up plan \"{$plan->name}\" allows {$plan->todaysLimit()} emails today."];
        }

        return null;
    }

    /**
     * Email under the plan accepted today, plus email being handed over right now, so parallel workers
     * do not each see room for one more.
     */
    public function sentToday(WarmupPlan $plan): int
    {
        $startOfDay = now()->startOfDay();

        return EmailMessage::query()
            ->whereHas('senderIdentity', fn ($senderIdentity) => $senderIdentity
                ->where('smtp_provider_id', $plan->smtp_provider_id)
                ->when($plan->sendingDomain, fn ($query, $domain) => $query->where('email', 'like', '%@'.$domain->domain)))
            ->where(fn ($messages) => $messages
                ->where(fn ($accepted) => $accepted->where('accepted_at', '>=', $startOfDay)->whereIn('status', MessageStatus::acceptedByProvider()))
                ->orWhere(fn ($sending) => $sending->where('status', MessageStatus::Sending)->where('sending_started_at', '>=', $startOfDay)))
            ->count();
    }

    private function windowStart(WarmupPlan $plan, CarbonInterface $day): CarbonInterface
    {
        return filled($plan->sending_window_start)
            ? Carbon::parse($day->toDateString().' '.$plan->sending_window_start)
            : $day->copy()->startOfDay();
    }

    private function windowEnd(WarmupPlan $plan, CarbonInterface $day): CarbonInterface
    {
        return filled($plan->sending_window_end)
            ? Carbon::parse($day->toDateString().' '.$plan->sending_window_end)
            : $day->copy()->endOfDay();
    }

    private function nextWindowStart(WarmupPlan $plan, CarbonInterface $now): CarbonInterface
    {
        $today = $this->windowStart($plan, $now);

        if ($now->lt($today) && $this->scheduler->isSendingDay($plan, $now) && $this->sentToday($plan) < $plan->todaysLimit()) {
            return $today;
        }

        return $this->windowStart($plan, $this->scheduler->nextSendingDay($plan, $now->copy()->startOfDay()));
    }
}
