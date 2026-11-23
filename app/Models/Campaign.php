<?php

namespace App\Models;

use App\Enums\AudienceType;
use App\Enums\CampaignStatus;
use App\Enums\EmailPurpose;
use App\Enums\MessageStatus;
use App\Enums\OutreachEvent;
use App\Models\Concerns\BelongsToClient;
use App\Services\Automation\OutreachEvents;
use App\Services\Campaigns\SendingWindow;
use App\Services\Sending\TemplateVariables;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'purpose', 'sender_identity_id', 'email_template_id', 'subject', 'body', 'text_body', 'required_variables', 'audience_type', 'segment_id', 'send_rate_per_minute', 'scheduled_at', 'send_days', 'send_window_start', 'send_window_end', 'timezone', 'use_prospect_timezone', 'track_opens', 'track_clicks'])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'audience_type' => 'lists',
        'send_rate_per_minute' => 60,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => EmailPurpose::class,
            'status' => CampaignStatus::class,
            'audience_type' => AudienceType::class,
            'send_rate_per_minute' => 'integer',
            'required_variables' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'prepared_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'submitted_by' => 'integer',
            'approved_by' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'approved_recipient_count' => 'integer',
            'send_days' => 'array',
            'use_prospect_timezone' => 'boolean',
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function clientConsistentRelations(): array
    {
        return ['senderIdentity', 'emailTemplate', 'segment'];
    }

    /**
     * @return BelongsTo<SenderIdentity, $this>
     */
    public function senderIdentity(): BelongsTo
    {
        return $this->belongsTo(SenderIdentity::class);
    }

    /**
     * @return BelongsTo<EmailTemplate, $this>
     */
    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    /**
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsToMany<ContactList, $this>
     */
    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(ContactList::class)->withTimestamps();
    }

    /**
     * Contacts chosen individually when the audience type is "selected contacts".
     *
     * @return BelongsToMany<Contact, $this>
     */
    public function selectedContacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)->withTimestamps();
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * Follow-up emails, in order. The campaign's own content is the first email.
     *
     * @return HasMany<CampaignStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class)->orderBy('position');
    }

    /**
     * Alternative versions (B, C, …) of the first email, for A/B testing.
     *
     * @return HasMany<CampaignVariant, $this>
     */
    public function firstEmailVariants(): HasMany
    {
        return $this->hasMany(CampaignVariant::class)->whereNull('campaign_step_id')->orderBy('position');
    }

    /**
     * Every alternative version, of the first email and of follow-ups.
     *
     * @return HasMany<CampaignVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(CampaignVariant::class);
    }

    /**
     * @return HasMany<CampaignProspect, $this>
     */
    public function prospects(): HasMany
    {
        return $this->hasMany(CampaignProspect::class);
    }

    /**
     * The follow-up that comes after the given step number (1 = the campaign's own email), or null after the last.
     */
    public function stepAfter(int $stepNumber): ?CampaignStep
    {
        if ($this->relationLoaded('steps')) {
            return $this->steps->first(fn (CampaignStep $step): bool => $step->position >= $stepNumber);
        }

        return $this->steps()->where('position', '>=', $stepNumber)->first();
    }

    public function totalSteps(): int
    {
        return 1 + $this->steps()->count();
    }

    /**
     * When the campaign may send to the contact. With "use prospect time zone", a contact's own valid time zone wins;
     * otherwise the campaign's, then the client's.
     */
    public function sendingWindowFor(?Contact $contact = null): SendingWindow
    {
        $timezone = match (true) {
            $this->use_prospect_timezone && SendingWindow::isValidTimezone($contact?->timezone) => $contact->timezone,
            SendingWindow::isValidTimezone($this->timezone) => $this->timezone,
            SendingWindow::isValidTimezone($this->client?->timezone) => $this->client->timezone,
            default => config('app.timezone'),
        };

        return new SendingWindow(
            days: $this->send_days === null ? null : array_map(intval(...), $this->send_days),
            start: $this->send_window_start,
            end: $this->send_window_end,
            timezone: $timezone,
        );
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Drafts and finished campaigns can be archived; running ones cannot.
     */
    public function canBeArchived(): bool
    {
        return ! $this->isArchived() && ($this->status === CampaignStatus::Draft || $this->status->isFinished());
    }

    /**
     * Every contact in the audience, before suppression and consent checks.
     *
     * @return Builder<Contact>
     */
    public function audienceQuery(): Builder
    {
        // Pinned to the campaign's client explicitly: preparation runs in a queue job, where no client may be current.
        $query = match ($this->audience_type) {
            AudienceType::AllContacts => Contact::query(),
            AudienceType::Lists => Contact::query()->whereHas(
                'lists',
                fn (Builder $lists) => $lists->whereIn('contact_lists.id', $this->lists()->pluck('contact_lists.id')),
            ),
            AudienceType::SelectedContacts => Contact::query()->whereHas(
                'campaigns',
                fn (Builder $campaigns) => $campaigns->whereKey($this->getKey()),
            ),
            AudienceType::Segment => $this->segment?->contactsQuery() ?? Contact::query()->whereRaw('1 = 0'),
        };

        return $query->where('contacts.client_id', $this->client_id);
    }

    /**
     * Contacts allowed to receive this email, before checking they have every required variable.
     *
     * @return Builder<Contact>
     */
    public function permittedAudienceQuery(): Builder
    {
        return $this->audienceQuery()->eligibleFor($this->purpose);
    }

    /**
     * Contacts that will receive the email: permitted, and with a value for every required variable.
     *
     * @return Builder<Contact>
     */
    public function eligibleAudienceQuery(): Builder
    {
        return app(TemplateVariables::class)->constrain($this->permittedAudienceQuery(), $this->required_variables ?? []);
    }

    /**
     * @return array<string, int>
     */
    public function messageCountsByStatus(): array
    {
        return $this->messages()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Change the status only if the campaign is still in one of the expected statuses, so two people
     * (or a person and a queue worker) acting at once cannot overwrite each other.
     *
     * With $onlyBeforeSending, a campaign that has ever started sending is never changed: it must not go back
     * to approval or draft once emails may have gone out.
     *
     * @param  list<CampaignStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(CampaignStatus $status, array $from, array $attributes = [], bool $onlyBeforeSending = false): bool
    {
        $changed = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', $from)
            ->when($onlyBeforeSending, fn (Builder $query) => $query->whereNull('started_at'))
            ->update(['status' => $status, ...$attributes]);

        $this->refresh();

        return $changed === 1;
    }

    /**
     * The most eligible recipients the campaign may start with before it needs approving again, or null when it
     * was launched before approvals existed.
     */
    public function approvedRecipientLimit(): ?int
    {
        return $this->approved_recipient_count === null ? null : static::recipientLimitFor($this->approved_recipient_count);
    }

    /**
     * The most eligible recipients allowed for an approved count, after the configured growth margin.
     */
    public static function recipientLimitFor(int $approvedCount): int
    {
        $marginPercent = max(0, (int) config('prosend.campaigns.approval.audience_growth_margin_percent'));

        return $approvedCount + (int) floor($approvedCount * $marginPercent / 100);
    }

    /**
     * Identifies exactly what is waiting for approval: the submission, the content, and the audience settings. An
     * approval only applies to the version the approver reviewed. Segment rules and list members are evaluated
     * when sending starts, so the approved recipient count guards those instead.
     */
    public function submissionFingerprint(): string
    {
        $selectedContacts = $this->selectedContacts()->toBase()->selectRaw('count(*) as total, coalesce(sum(contacts.id), 0) as id_sum')->first();

        return hash('sha256', (string) json_encode([
            $this->submitted_at?->toIso8601String(),
            $this->updated_at?->toIso8601String(),
            $this->scheduled_at?->toIso8601String(),
            $this->only(['name', 'purpose', 'sender_identity_id', 'email_template_id', 'subject', 'body', 'text_body', 'required_variables', 'audience_type', 'segment_id', 'send_rate_per_minute', 'send_days', 'send_window_start', 'send_window_end', 'timezone', 'use_prospect_timezone', 'track_opens', 'track_clicks']),
            $this->steps()->get(['position', 'wait_days', 'condition', 'subject', 'body', 'text_body'])->toArray(),
            $this->variants()->orderBy('id')->get(['campaign_step_id', 'position', 'weight', 'subject', 'body', 'text_body', 'is_active'])->toArray(),
            $this->lists()->pluck('contact_lists.id')->sort()->values()->all(),
            [(int) $selectedContacts?->total, (int) $selectedContacts?->id_sum],
        ]));
    }

    /**
     * Close a sending campaign once it is fully prepared and none of its messages are waiting or in flight.
     */
    public function syncCompletionStatus(): void
    {
        if ($this->prepared_at === null) {
            return;
        }

        if ($this->messages()->whereIn('status', MessageStatus::inFlight())->exists()) {
            return;
        }

        // Follow-ups still waiting keep the campaign active.
        if ($this->prospects()->active()->whereNotNull('next_step_due_at')->exists()) {
            return;
        }

        $attempted = $this->messages()->whereNotIn('status', [MessageStatus::Cancelled, MessageStatus::Suppressed])->count();
        $failed = $this->messages()->whereIn('status', [MessageStatus::Failed, MessageStatus::Unknown])->count();

        $finalStatus = match (true) {
            $failed === 0 => CampaignStatus::Completed,
            $failed >= $attempted => CampaignStatus::Failed,
            default => CampaignStatus::PartiallyFailed,
        };

        $completed = static::query()
            ->whereKey($this->getKey())
            ->where('status', CampaignStatus::Sending)
            ->update(['status' => $finalStatus, 'completed_at' => now()]);

        $this->refresh();

        if ($completed === 1) {
            app(OutreachEvents::class)->dispatch(OutreachEvent::CampaignCompleted, $this->client_id, null, ['campaign_id' => $this->getKey(), 'campaign' => $this->name, 'status' => $finalStatus->value], "campaign-completed:{$this->getKey()}:{$this->completed_at?->getTimestamp()}");
        }
    }
}
