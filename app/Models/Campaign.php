<?php

namespace App\Models;

use App\Enums\AudienceType;
use App\Enums\CampaignStatus;
use App\Enums\EmailPurpose;
use App\Enums\MessageStatus;
use App\Services\Sending\TemplateVariables;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'purpose', 'sender_identity_id', 'email_template_id', 'subject', 'body', 'text_body', 'required_variables', 'audience_type', 'segment_id', 'send_rate_per_minute', 'scheduled_at'])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

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
        ];
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
     * Every contact in the audience, before suppression and consent checks.
     *
     * @return Builder<Contact>
     */
    public function audienceQuery(): Builder
    {
        return match ($this->audience_type) {
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
     * @param  list<CampaignStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(CampaignStatus $status, array $from, array $attributes = []): bool
    {
        $changed = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', $from)
            ->update(['status' => $status, ...$attributes]);

        $this->refresh();

        return $changed === 1;
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

        $attempted = $this->messages()->whereNotIn('status', [MessageStatus::Cancelled, MessageStatus::Suppressed])->count();
        $failed = $this->messages()->whereIn('status', [MessageStatus::Failed, MessageStatus::Unknown])->count();

        $finalStatus = match (true) {
            $failed === 0 => CampaignStatus::Completed,
            $failed >= $attempted => CampaignStatus::Failed,
            default => CampaignStatus::PartiallyFailed,
        };

        static::query()
            ->whereKey($this->getKey())
            ->where('status', CampaignStatus::Sending)
            ->update(['status' => $finalStatus, 'completed_at' => now()]);

        $this->refresh();
    }
}
