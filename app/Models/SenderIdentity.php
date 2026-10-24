<?php

namespace App\Models;

use Database\Factories\SenderIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['smtp_provider_id', 'name', 'email', 'reply_to'])]
class SenderIdentity extends Model
{
    /** @use HasFactory<SenderIdentityFactory> */
    use HasFactory;

    /**
     * Verification belongs to an address at a provider, so changing either clears it.
     */
    protected static function booted(): void
    {
        static::updating(function (SenderIdentity $senderIdentity): void {
            if ($senderIdentity->isDirty(['email', 'smtp_provider_id'])) {
                $senderIdentity->verified_at = null;
                $senderIdentity->verified_by = null;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
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
     * @return BelongsTo<SmtpProvider, $this>
     */
    public function smtpProvider(): BelongsTo
    {
        return $this->belongsTo(SmtpProvider::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Identities that may send right now: on an enabled provider, and verified when verification is required.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query
            ->whereHas('smtpProvider', fn (Builder $provider) => $provider->where('is_enabled', true))
            ->when(
                config('prosend.require_verified_senders'),
                fn (Builder $identities) => $identities->whereNotNull('verified_at'),
            );
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function canSend(): bool
    {
        return $this->isVerified() || ! config('prosend.require_verified_senders');
    }

    public function domain(): string
    {
        return Str::after($this->email, '@');
    }

    public function markVerified(User $verifier): void
    {
        $this->forceFill([
            'verified_at' => now(),
            'verified_by' => $verifier->getKey(),
        ])->save();
    }

    public function revokeVerification(): void
    {
        $this->forceFill([
            'verified_at' => null,
            'verified_by' => null,
        ])->save();
    }
}
