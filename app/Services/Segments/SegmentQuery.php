<?php

namespace App\Services\Segments;

use App\Enums\ConsentStatus;
use App\Enums\EmailEventType;
use App\Enums\MessageStatus;
use App\Enums\SegmentMatch;
use App\Models\Contact;
use App\Models\Segment;
use App\Services\Sending\TemplateVariables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns a segment's rules into a contact query. Each rule is a Filament builder block: {type, data}.
 */
class SegmentQuery
{
    /**
     * Rule types and their labels, in the order they are offered.
     *
     * @var array<string, string>
     */
    public const RULE_TYPES = [
        'consent' => 'Consent status',
        'list' => 'List membership',
        'tag' => 'Tag',
        'email_domain' => 'Email domain',
        'company' => 'Company',
        'custom_attribute' => 'Custom attribute',
        'added' => 'Date added',
        'unsubscribed' => 'Unsubscribed',
        'engagement' => 'Opened or clicked',
        'received' => 'Received email recently',
    ];

    /**
     * @param  Builder<Contact>  $contacts
     * @return Builder<Contact>
     */
    public function apply(Builder $contacts, Segment $segment): Builder
    {
        $rules = array_values(array_filter($segment->rules ?? [], fn (mixed $rule): bool => is_array($rule) && isset(self::RULE_TYPES[$rule['type'] ?? ''])));

        if ($rules === []) {
            return $contacts->whereRaw('1 = 0');
        }

        $boolean = $segment->match === SegmentMatch::Any ? 'or' : 'and';

        return $contacts->where(function (Builder $query) use ($rules, $boolean): void {
            foreach ($rules as $rule) {
                $query->where(fn (Builder $ruleQuery) => $this->applyRule($ruleQuery, $rule['type'], $rule['data'] ?? []), boolean: $boolean);
            }
        });
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>  $data
     */
    private function applyRule(Builder $query, string $type, array $data): void
    {
        $negate = in_array($data['operator'] ?? null, ['not_in', 'missing', 'is_empty', 'not_received'], true);

        match ($type) {
            'consent' => ($status = ConsentStatus::tryFrom((string) ($data['status'] ?? ''))) ? $query->where('consent_status', $status) : $query->whereRaw('1 = 0'),
            'list' => $negate
                ? $query->whereDoesntHave('lists', fn (Builder $lists) => $lists->whereKey((int) ($data['contact_list_id'] ?? 0)))
                : $query->whereHas('lists', fn (Builder $lists) => $lists->whereKey((int) ($data['contact_list_id'] ?? 0))),
            'tag' => $negate
                ? $query->where(fn (Builder $tags) => $tags->whereNull('tags')->orWhereJsonDoesntContain('tags', (string) ($data['tag'] ?? '')))
                : $query->whereJsonContains('tags', (string) ($data['tag'] ?? '')),
            'email_domain' => $this->applyEmailDomain($query, (string) ($data['domain'] ?? '')),
            'company' => match ($data['operator'] ?? 'contains') {
                'is_empty' => $query->where(fn (Builder $company) => $company->whereNull('company')->orWhere('company', '')),
                'is_not_empty' => $query->whereNotNull('company')->where('company', '!=', ''),
                default => $query->where('company', 'like', '%'.addcslashes((string) ($data['value'] ?? ''), '%_\\').'%'),
            },
            'custom_attribute' => $this->applyCustomAttribute($query, $data),
            'added' => $this->applyDate($query, $data),
            'unsubscribed' => ($data['value'] ?? 'no') === 'yes' ? $query->whereNotNull('unsubscribed_at') : $query->whereNull('unsubscribed_at'),
            'engagement' => $query->whereHas('emailMessages.events', fn (Builder $events) => $events
                ->whereIn('type', ($data['event'] ?? 'opened') === 'clicked' ? [EmailEventType::Clicked] : [EmailEventType::Opened, EmailEventType::Clicked])
                ->where('occurred_at', '>=', now()->subDays(max(1, (int) ($data['days'] ?? 30))))),
            'received' => $query->{$negate ? 'whereDoesntHave' : 'whereHas'}('emailMessages', fn (Builder $messages) => $messages
                ->whereIn('status', MessageStatus::acceptedByProvider())
                ->where('accepted_at', '>=', now()->subDays(max(1, (int) ($data['days'] ?? 7))))),
        };
    }

    /**
     * Only plain domain names are accepted, so characters such as % can never widen the match.
     *
     * @param  Builder<Contact>  $query
     */
    private function applyEmailDomain(Builder $query, string $domain): void
    {
        $domain = Str::lower(ltrim(trim($domain), '@'));

        preg_match('/^[a-z0-9.-]+$/', $domain) === 1
            ? $query->where('email', 'like', '%@'.$domain)
            : $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>  $data
     */
    private function applyCustomAttribute(Builder $query, array $data): void
    {
        $key = (string) ($data['key'] ?? '');

        // The key becomes part of a JSON path, so only plain attribute names are accepted.
        if (preg_match(TemplateVariables::KEY_PATTERN, $key) !== 1) {
            $query->whereRaw('1 = 0');

            return;
        }

        $column = "custom_attributes->{$key}";

        match ($data['operator'] ?? 'equals') {
            'exists' => $query->whereNotNull($column)->where($column, '!=', ''),
            'missing' => $query->where(fn (Builder $attribute) => $attribute->whereNull($column)->orWhere($column, '')),
            default => $query->where($column, (string) ($data['value'] ?? '')),
        };
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>  $data
     */
    private function applyDate(Builder $query, array $data): void
    {
        match ($data['operator'] ?? 'within_days') {
            'before' => $query->where('created_at', '<', Carbon::parse((string) ($data['date'] ?? 'today'))->startOfDay()),
            'after' => $query->where('created_at', '>=', Carbon::parse((string) ($data['date'] ?? 'today'))->startOfDay()),
            default => $query->where('created_at', '>=', now()->subDays(max(1, (int) ($data['days'] ?? 30)))),
        };
    }
}
