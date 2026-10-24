<?php

namespace App\Models;

use App\Enums\ConsentStatus;
use App\Enums\EmailPurpose;
use App\Enums\SuppressionReason;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['email', 'first_name', 'last_name', 'company', 'phone', 'source', 'tags', 'custom_attributes', 'consent_status', 'consent_source', 'consented_at', 'unsubscribed_at'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'consent_status' => 'unknown',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'custom_attributes' => 'array',
            'consent_status' => ConsentStatus::class,
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
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
     * @return Attribute<?string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => trim("{$this->first_name} {$this->last_name}") ?: null,
        );
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
