<?php

namespace App\Services\Analytics;

use App\Enums\BounceType;
use App\Enums\EmailEventType;
use App\Enums\MessageStatus;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Delivery metrics for the messages in a period. Every figure is counted from stored messages and
 * provider events; nothing is estimated. By default the period applies to when a message was created;
 * pass 'period_by' => 'accepted' to judge by when providers accepted it (used for warm-up decisions,
 * because campaign messages are created up front but sent over several days).
 */
class EmailMetrics
{
    /**
     * @param  array<string, mixed>|null  $filters
     * @return Builder<EmailMessage>
     */
    public function messages(?array $filters): Builder
    {
        [$start, $end] = $this->period($filters);
        $dateColumn = ($filters['period_by'] ?? null) === 'accepted' ? 'email_messages.accepted_at' : 'email_messages.created_at';

        return $this->filter(
            EmailMessage::query()
                ->whereBetween($dateColumn, [$start, $end])
                ->where('email_messages.status', '!=', MessageStatus::Simulated),
            $filters,
        );
    }

    /**
     * Apply every filter except the period.
     *
     * @param  Builder<EmailMessage>  $messages
     * @param  array<string, mixed>|null  $filters
     * @return Builder<EmailMessage>
     */
    public function filter(Builder $messages, ?array $filters): Builder
    {
        return $messages
            ->when($filters['purpose'] ?? null, fn (Builder $query, string $purpose) => $query->where('email_messages.purpose', $purpose))
            ->when($filters['smtp_provider_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('email_messages.smtp_provider_id', $id))
            ->when($filters['campaign_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('email_messages.campaign_id', $id))
            ->when($filters['sending_domain'] ?? null, fn (Builder $query, string $domain) => preg_match('/^[a-z0-9.-]+$/i', $domain) === 1
                ? $query->whereHas('senderIdentity', fn (Builder $identity) => $identity->where('email', 'like', '%@'.$domain))
                : $query->whereRaw('1 = 0'));
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{submitted: int, accepted: int, delivered: int, hard_bounces: int, soft_bounces: int, complaints: int, failed: int, unknown: int, suppressed: int, unsubscribes: int, opened: int, clicked: int}
     */
    public function summary(?array $filters): array
    {
        $row = $this->messages($filters)
            ->toBase()
            ->selectRaw('count(*) as submitted')
            ->selectRaw('sum(case when status in ('.$this->placeholders(MessageStatus::acceptedByProvider()).') then 1 else 0 end) as accepted', $this->values(MessageStatus::acceptedByProvider()))
            ->selectRaw('sum(case when delivered_at is not null then 1 else 0 end) as delivered')
            ->selectRaw('sum(case when bounce_type = ? then 1 else 0 end) as hard_bounces', [BounceType::Hard->value])
            ->selectRaw('sum(case when bounce_type = ? then 1 else 0 end) as soft_bounces', [BounceType::Soft->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as complaints', [MessageStatus::Complained->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [MessageStatus::Failed->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as unknown', [MessageStatus::Unknown->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as suppressed', [MessageStatus::Suppressed->value])
            ->first();

        $events = EmailEvent::query()
            ->whereIn('email_message_id', $this->messages($filters)->select('email_messages.id'))
            ->whereIn('type', [EmailEventType::Unsubscribed, EmailEventType::Opened, EmailEventType::Clicked])
            ->toBase()
            ->selectRaw('type, count(distinct email_message_id) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        return [
            'submitted' => (int) $row->submitted,
            'accepted' => (int) $row->accepted,
            'delivered' => (int) $row->delivered,
            'hard_bounces' => (int) $row->hard_bounces,
            'soft_bounces' => (int) $row->soft_bounces,
            'complaints' => (int) $row->complaints,
            'failed' => (int) $row->failed,
            'unknown' => (int) $row->unknown,
            'suppressed' => (int) $row->suppressed,
            'unsubscribes' => (int) ($events[EmailEventType::Unsubscribed->value] ?? 0),
            'opened' => (int) ($events[EmailEventType::Opened->value] ?? 0),
            'clicked' => (int) ($events[EmailEventType::Clicked->value] ?? 0),
        ];
    }

    /**
     * Daily counts across the period, including days with no email.
     *
     * @param  array<string, mixed>|null  $filters
     * @return array<string, array{submitted: int, accepted: int, delivered: int, problems: int, hard_bounces: int, complaints: int}>
     */
    public function daily(?array $filters): array
    {
        [$start, $end] = $this->period($filters);

        $rows = $this->messages($filters)
            ->toBase()
            ->selectRaw('date(email_messages.created_at) as day')
            ->selectRaw('count(*) as submitted')
            ->selectRaw('sum(case when status in ('.$this->placeholders(MessageStatus::acceptedByProvider()).') then 1 else 0 end) as accepted', $this->values(MessageStatus::acceptedByProvider()))
            ->selectRaw('sum(case when delivered_at is not null then 1 else 0 end) as delivered')
            ->selectRaw('sum(case when status in (?, ?, ?, ?) then 1 else 0 end) as problems', [
                MessageStatus::Failed->value,
                MessageStatus::Bounced->value,
                MessageStatus::Complained->value,
                MessageStatus::Unknown->value,
            ])
            ->selectRaw('sum(case when bounce_type = ? then 1 else 0 end) as hard_bounces', [BounceType::Hard->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as complaints', [MessageStatus::Complained->value])
            ->groupBy(DB::raw('date(email_messages.created_at)'))
            ->get()
            ->keyBy('day');

        $days = [];

        for ($day = $start->copy()->startOfDay(); $day->lte($end); $day->addDay()) {
            $row = $rows->get($day->toDateString());

            $days[$day->toDateString()] = [
                'submitted' => (int) ($row->submitted ?? 0),
                'accepted' => (int) ($row->accepted ?? 0),
                'delivered' => (int) ($row->delivered ?? 0),
                'problems' => (int) ($row->problems ?? 0),
                'hard_bounces' => (int) ($row->hard_bounces ?? 0),
                'complaints' => (int) ($row->complaints ?? 0),
            ];
        }

        return $days;
    }

    /**
     * Accepted email per sending domain, largest first.
     *
     * @param  array<string, mixed>|null  $filters
     * @return array<string, int>
     */
    public function acceptedByDomain(?array $filters): array
    {
        $counts = $this->messages($filters)
            ->whereIn('email_messages.status', MessageStatus::acceptedByProvider())
            ->toBase()
            ->selectRaw('sender_identity_id, count(*) as aggregate')
            ->groupBy('sender_identity_id')
            ->pluck('aggregate', 'sender_identity_id');

        $domains = SenderIdentity::query()
            ->whereKey($counts->keys())
            ->pluck('email', 'id')
            ->map(fn (string $email): string => Str::after($email, '@'));

        return $counts
            ->groupBy(fn (mixed $count, int|string $senderIdentityId): string => $domains[$senderIdentityId] ?? 'unknown', preserveKeys: true)
            ->map(fn ($group): int => (int) $group->sum())
            ->sortDesc()
            ->all();
    }

    /**
     * Accepted email per hour over the last 24 hours, for queue throughput.
     *
     * @return array<string, int>
     */
    public function acceptedPerHour(): array
    {
        $since = now()->subHours(23)->startOfHour();
        $hourExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d %H:00', accepted_at)"
            : "DATE_FORMAT(accepted_at, '%Y-%m-%d %H:00')";

        $accepted = EmailMessage::query()
            ->where('accepted_at', '>=', $since)
            ->toBase()
            ->selectRaw("{$hourExpression} as hour, count(*) as aggregate")
            ->groupBy(DB::raw($hourExpression))
            ->pluck('aggregate', 'hour');

        $hours = [];

        for ($hour = $since->copy(); $hour->lte(now()); $hour->addHour()) {
            $hours[$hour->format('H:00')] = (int) ($accepted[$hour->format('Y-m-d H:00')] ?? 0);
        }

        return $hours;
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<string, int>
     */
    public function statusBreakdown(?array $filters): array
    {
        return $this->messages($filters)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(?array $filters): array
    {
        $start = filled($filters['start_date'] ?? null) ? Carbon::parse($filters['start_date'])->startOfDay() : now()->subDays(29)->startOfDay();
        $end = filled($filters['end_date'] ?? null) ? Carbon::parse($filters['end_date'])->endOfDay() : now()->endOfDay();

        return $start->lte($end) ? [$start, $end] : [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
    }

    /**
     * @param  list<MessageStatus>  $statuses
     */
    private function placeholders(array $statuses): string
    {
        return implode(', ', array_fill(0, count($statuses), '?'));
    }

    /**
     * @param  list<MessageStatus>  $statuses
     * @return list<string>
     */
    private function values(array $statuses): array
    {
        return array_map(fn (MessageStatus $status): string => $status->value, $statuses);
    }
}
