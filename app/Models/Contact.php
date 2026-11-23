<?php

namespace App\Models;

use App\Enums\ConsentStatus;
use App\Enums\ContactStage;
use App\Enums\EmailPurpose;
use App\Enums\OutreachEvent;
use App\Enums\SequenceStatus;
use App\Enums\SuppressionReason;
use App\Models\Concerns\BelongsToClient;
use App\Services\Automation\OutreachEvents;
use App\Services\Campaigns\SequenceProgress;
use Carbon\CarbonInterface;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['email', 'first_name', 'last_name', 'company', 'job_title', 'industry', 'website', 'timezone', 'phone', 'source', 'stage', 'owner_id', 'tags', 'custom_attributes', 'consent_status', 'consent_source', 'consented_at', 'unsubscribed_at', 'last_contacted_at', 'last_replied_at', 'notes'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'consent_status' => 'unknown',
        'stage' => 'new',
    ];

    /**
     * A recorded reply ends the prospect's sequences: nobody gets a follow-up after answering.
     */
    protected static function booted(): void
    {
        static::created(fn (Contact $contact) => app(OutreachEvents::class)->dispatch(OutreachEvent::ProspectCreated, $contact->client_id, $contact, ['source' => $contact->source], "contact:{$contact->getKey()}"));

        static::updated(function (Contact $contact): void {
            if ($contact->wasChanged('stage')) {
                app(OutreachEvents::class)->dispatch(OutreachEvent::ProspectStageChanged, $contact->client_id, $contact, [
                    'previous_stage' => $contact->getOriginal('stage')?->value,
                    'stage' => $contact->stage->value,
                ], "stage:{$contact->getKey()}:{$contact->stage->value}:{$contact->updated_at?->getTimestamp()}");
            }

            $replied = ($contact->wasChanged('last_replied_at') && $contact->last_replied_at !== null)
                || ($contact->wasChanged('stage') && $contact->stage->meansReplied());

            if ($replied) {
                rescue(fn () => app(SequenceProgress::class)->stopForContact($contact, SequenceStatus::Replied, 'Replied'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'custom_attributes' => 'array',
            'consent_status' => ConsentStatus::class,
            'stage' => ContactStage::class,
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'last_replied_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::lower(trim($value)));
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function website(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => static::normalizeWebsite($value));
    }

    /**
     * A website without a scheme, such as "example.com" or "example.com:8080", is stored as https.
     */
    public static function normalizeWebsite(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // A colon followed by a digit starts a port, not a scheme.
        return preg_match('/^[a-z][a-z0-9+.-]*:(?!\d)/i', $value) === 1 ? $value : "https://{$value}";
    }

    /**
     * The website as a link target, or null when it is not an http(s) address.
     */
    public function websiteUrl(): ?string
    {
        return preg_match('#^https?://#i', (string) $this->website) === 1 ? $this->website : null;
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => trim("{$this->first_name} {$this->last_name}") ?: null,
        );
    }

    /**
     * The user responsible for this contact.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<ContactList, $this>
     */
    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(ContactList::class)->withTimestamps();
    }

    /**
     * Campaigns that target this contact individually.
     *
     * @return BelongsToMany<Campaign, $this>
     */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class)->withTimestamps();
    }

    /**
     * @return HasOne<Suppression, $this>
     */
    public function suppression(): HasOne
    {
        return $this->hasOne(Suppression::class, 'email', 'email');
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * Contacts whose column contains the text. % and _ in the text match literally, on every database driver.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function whereContains(Builder $query, string $column, string $text): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);

        $query->whereRaw($query->getQuery()->getGrammar()->wrap($column)." like ? escape '!'", ["%{$escaped}%"]);
    }

    /**
     * Whether a reply was recorded after the given moment. A stage change to Replied ends sequences when it happens,
     * through the updated hook, so it is not checked here: a customer marked Converted long ago may join a new campaign.
     */
    public function hasRepliedSince(?CarbonInterface $moment): bool
    {
        return $this->last_replied_at !== null && ($moment === null || $this->last_replied_at->greaterThanOrEqualTo($moment));
    }

    /**
     * Contacts that may receive email of the given purpose. Marketing additionally requires
     * recorded consent and no unsubscribe.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function eligibleFor(Builder $query, EmailPurpose $purpose): void
    {
        $query->whereDoesntHave(
            'suppression',
            fn (Builder $suppression) => $suppression->whereIn('reason', SuppressionReason::blocking($purpose)),
        );

        if ($purpose === EmailPurpose::Marketing) {
            $query
                ->whereNull('unsubscribed_at')
                ->where('consent_status', ConsentStatus::Granted);
        }
    }

    /**
     * Record that a provider accepted an outreach email to the contact. A contact not yet contacted moves to Contacted.
     */
    public static function recordContacted(int $contactId): void
    {
        static::query()->whereKey($contactId)->update(['last_contacted_at' => now()]);

        static::query()
            ->whereKey($contactId)
            ->whereIn('stage', ContactStage::notYetContacted())
            ->update(['stage' => ContactStage::Contacted]);
    }

    /**
     * Why the contact cannot receive email of the given purpose, or null when it can.
     */
    public function ineligibilityReason(EmailPurpose $purpose): ?string
    {
        $suppression = Suppression::blockingFor($this->email, $purpose);

        if ($suppression) {
            return "suppressed ({$suppression->reason->getLabel()})";
        }

        if ($purpose !== EmailPurpose::Marketing) {
            return null;
        }

        if ($this->unsubscribed_at !== null) {
            return 'unsubscribed';
        }

        if ($this->consent_status !== ConsentStatus::Granted) {
            return 'no marketing consent recorded';
        }

        return null;
    }
}
