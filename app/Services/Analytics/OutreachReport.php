<?php

namespace App\Services\Analytics;

use App\Enums\BounceType;
use App\Enums\ConversationStatus;
use App\Enums\EmailEventType;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\Scopes\ClientScope;
use App\Models\SenderIdentity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Outreach results for the emails providers accepted in a period, grouped by campaign, person, team, mailbox, day or
 * (for Super Admins) client. Every figure is counted from stored emails, events and conversations.
 *
 * Rates are shares of emails sent. Opens and clicks are approximate (privacy features and link scanners); replies come
 * from inbound mailboxes; positive replies and conversions are prospects whose conversation is marked Interested or
 * Converted, so their rate is a share of the prospects who replied (not of reply emails).
 */
class OutreachReport
{
    public const GROUPINGS = [
        'campaign' => 'Campaigns',
        'user' => 'People',
        'team' => 'Teams',
        'mailbox' => 'Mailboxes',
        'day' => 'Days',
        'client' => 'Clients',
    ];

    /**
     * Report columns in order: key => heading.
     *
     * @var array<string, string>
     */
    public const COLUMNS = [
        'label' => 'Name',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'opened' => 'Opened',
        'open_rate' => 'Open rate %',
        'clicked' => 'Clicked',
        'click_rate' => 'Click rate %',
        'replied' => 'Replied',
        'reply_rate' => 'Reply rate %',
        'positive' => 'Positive replies',
        'positive_rate' => 'Positive % of replying prospects',
        'converted' => 'Converted',
        'conversion_rate' => 'Conversion rate %',
        'bounced' => 'Hard bounces',
        'bounce_rate' => 'Bounce rate %',
        'unsubscribed' => 'Unsubscribed',
        'unsubscribe_rate' => 'Unsubscribe rate %',
    ];

    public function __construct(private EmailMetrics $metrics) {}

    /**
     * @param  array<string, mixed>|null  $filters  start_date, end_date, campaign_ids (list), and EmailMetrics filters.
     * @return array{sent: int, delivered: int, opened: int, clicked: int, replied: int, replied_prospects: int, positive: int, converted: int, bounced: int, unsubscribed: int}
     */
    public function totals(?array $filters, bool $acrossClients = false): array
    {
        $rows = $this->aggregate($filters, null, $acrossClients);

        return $rows->first() ?? $this->emptyRow();
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return list<array<string, mixed>> One row per group: label, the counts, and rates in percent.
     */
    public function rows(string $grouping, ?array $filters, bool $acrossClients = false): array
    {
        if ($grouping === 'team') {
            return $this->teamRows($filters);
        }

        $column = match ($grouping) {
            'campaign' => 'email_messages.campaign_id',
            'user' => 'email_messages.created_by',
            'mailbox' => 'email_messages.sender_identity_id',
            'day' => 'date(email_messages.accepted_at)',
            'client' => 'email_messages.client_id',
        };

        $rows = $this->aggregate($filters, $column, $acrossClients && $grouping === 'client');
        $labels = $this->labels($grouping, $rows->keys()->all());

        return $rows
            ->map(fn (array $row, string|int $key): array => $this->withRates(['label' => $labels[$key] ?? $this->fallbackLabel($grouping, $key), ...$row]))
            ->sortBy($grouping === 'day' ? 'label' : fn (array $row): int => -$row['sent'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $row
     * @return array<string, int|float|string>
     */
    public function withRates(array $row): array
    {
        $rate = fn (int $count, int $of): float => $of > 0 ? round($count / $of * 100, 1) : 0.0;

        return [
            ...$row,
            'open_rate' => $rate($row['opened'], $row['sent']),
            'click_rate' => $rate($row['clicked'], $row['sent']),
            'reply_rate' => $rate($row['replied'], $row['sent']),
            // A conversation can be marked Interested after only an automatic reply, so the share is capped at 100%.
            'positive_rate' => min(100.0, $rate($row['positive'], $row['replied_prospects'] ?? $row['replied'])),
            'conversion_rate' => $rate($row['converted'], $row['sent']),
            'bounce_rate' => $rate($row['bounced'], $row['sent']),
            'unsubscribe_rate' => $rate($row['unsubscribed'], $row['sent']),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return Collection<string|int, array{sent: int, delivered: int, opened: int, clicked: int, replied: int, replied_prospects: int, positive: int, converted: int, bounced: int, unsubscribed: int}>
     */
    private function aggregate(?array $filters, ?string $groupColumn, bool $acrossClients): Collection
    {
        $group = $groupColumn ?? "'all'";
        $messages = $this->messages($filters, $acrossClients);

        $sent = (clone $messages)->toBase()
            ->selectRaw("{$group} as grp, count(*) as sent")
            ->selectRaw('sum(case when email_messages.delivered_at is not null then 1 else 0 end) as delivered')
            ->selectRaw('sum(case when email_messages.bounce_type = ? then 1 else 0 end) as bounced', [BounceType::Hard->value])
            ->groupByRaw($group)
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->grp);

        $events = EmailEvent::query()
            ->joinSub((clone $messages)->select(['email_messages.id', 'email_messages.contact_id'])->selectRaw("{$group} as grp"), 'reported', 'reported.id', '=', 'email_events.email_message_id')
            ->whereIn('email_events.type', [EmailEventType::Opened, EmailEventType::Clicked, EmailEventType::Replied, EmailEventType::Unsubscribed])
            ->toBase()
            ->selectRaw('reported.grp as grp, email_events.type as type, count(distinct email_events.email_message_id) as aggregate, count(distinct reported.contact_id) as prospects')
            ->groupBy('reported.grp', 'email_events.type')
            ->get()
            ->groupBy(fn (object $row): string => (string) $row->grp);

        $outcomes = $this->conversationOutcomes($messages, $group);

        return $sent->map(function (object $row, string $key) use ($events, $outcomes): array {
            $byType = collect($events->get($key, []))->pluck('aggregate', 'type');
            $prospectsByType = collect($events->get($key, []))->pluck('prospects', 'type');

            return [
                'sent' => (int) $row->sent,
                'delivered' => (int) $row->delivered,
                'opened' => (int) ($byType[EmailEventType::Opened->value] ?? 0),
                'clicked' => (int) ($byType[EmailEventType::Clicked->value] ?? 0),
                'replied' => (int) ($byType[EmailEventType::Replied->value] ?? 0),
                'replied_prospects' => (int) ($prospectsByType[EmailEventType::Replied->value] ?? 0),
                'positive' => (int) ($outcomes[$key]['positive'] ?? 0),
                'converted' => (int) ($outcomes[$key]['converted'] ?? 0),
                'bounced' => (int) $row->bounced,
                'unsubscribed' => (int) ($byType[EmailEventType::Unsubscribed->value] ?? 0),
            ];
        });
    }

    /**
     * Prospects whose conversation about these emails is Interested or Converted, counted once per prospect and group.
     *
     * @param  Builder<EmailMessage>  $messages
     * @return array<string, array{positive: int, converted: int}>
     */
    private function conversationOutcomes(Builder $messages, string $group): array
    {
        $outcomes = [];

        Conversation::query()
            ->withoutGlobalScope(ClientScope::class)
            ->joinSub((clone $messages)->select(['email_messages.contact_id', 'email_messages.campaign_id', 'email_messages.client_id'])->selectRaw("{$group} as grp")->distinct(), 'reported', function ($join): void {
                $join->on('reported.contact_id', '=', 'conversations.contact_id')
                    ->on('reported.client_id', '=', 'conversations.client_id')
                    ->whereRaw('coalesce(reported.campaign_id, 0) = coalesce(conversations.campaign_id, 0)');
            })
            ->whereIn('conversations.status', ConversationStatus::positive())
            ->toBase()
            ->selectRaw('reported.grp as grp, conversations.status as status, count(distinct conversations.contact_id) as aggregate')
            ->groupBy('reported.grp', 'conversations.status')
            ->get()
            ->each(function (object $row) use (&$outcomes): void {
                $key = (string) $row->grp;
                $outcomes[$key]['positive'] = ($outcomes[$key]['positive'] ?? 0) + (int) $row->aggregate;

                if ($row->status === ConversationStatus::Converted->value) {
                    $outcomes[$key]['converted'] = ($outcomes[$key]['converted'] ?? 0) + (int) $row->aggregate;
                }
            });

        return $outcomes;
    }

    /**
     * Emails accepted in the period, limited to the chosen campaigns when there are any.
     *
     * @param  array<string, mixed>|null  $filters
     * @return Builder<EmailMessage>
     */
    private function messages(?array $filters, bool $acrossClients): Builder
    {
        $messages = $this->metrics->messages([...($filters ?? []), 'period_by' => 'accepted'])
            ->whereNotNull('email_messages.accepted_at')
            ->when(filled($filters['campaign_ids'] ?? null), fn (Builder $query) => $query->whereIn('email_messages.campaign_id', (array) $filters['campaign_ids']));

        return $acrossClients ? $messages->withoutGlobalScope(ClientScope::class) : $messages;
    }

    /**
     * People's figures, added up per team. Someone in two teams counts in both.
     *
     * @param  array<string, mixed>|null  $filters
     * @return list<array<string, mixed>>
     */
    private function teamRows(?array $filters): array
    {
        $byUser = $this->aggregate($filters, 'email_messages.created_by', false);

        return Team::query()->with('members:id')->orderBy('name')->get()
            ->map(function (Team $team) use ($byUser): array {
                $totals = $this->emptyRow();

                foreach ($team->members as $member) {
                    foreach ($byUser->get((string) $member->getKey(), []) as $metric => $count) {
                        $totals[$metric] += $count;
                    }
                }

                return $this->withRates(['label' => $team->name, ...$totals]);
            })
            ->all();
    }

    /**
     * @param  list<string|int>  $keys
     * @return array<string|int, string>
     */
    private function labels(string $grouping, array $keys): array
    {
        $ids = array_filter($keys, fn (string|int $key): bool => is_numeric($key) && (int) $key > 0);

        return match ($grouping) {
            'campaign' => Campaign::query()->whereKey($ids)->pluck('name', 'id')->all(),
            'user' => User::query()->whereKey($ids)->pluck('name', 'id')->all(),
            'mailbox' => SenderIdentity::query()->whereKey($ids)->pluck('email', 'id')->all(),
            'client' => Client::query()->whereKey($ids)->pluck('name', 'id')->all(),
            default => array_combine($keys, array_map('strval', $keys)) ?: [],
        };
    }

    private function fallbackLabel(string $grouping, string|int $key): string
    {
        return match ($grouping) {
            'campaign' => 'Individual and API email',
            'user' => 'API and automation',
            default => (string) $key,
        };
    }

    /**
     * @return array{sent: int, delivered: int, opened: int, clicked: int, replied: int, replied_prospects: int, positive: int, converted: int, bounced: int, unsubscribed: int}
     */
    private function emptyRow(): array
    {
        return ['sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0, 'replied_prospects' => 0, 'positive' => 0, 'converted' => 0, 'bounced' => 0, 'unsubscribed' => 0];
    }
}
