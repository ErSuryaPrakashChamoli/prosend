<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Enums\ProviderType;
use App\Models\Concerns\BelongsToClient;
use App\Observers\SenderIdentityObserver;
use Database\Factories\SenderIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

#[ObservedBy(SenderIdentityObserver::class)]
#[Fillable(['smtp_provider_id', 'name', 'email', 'reply_to'])]
class SenderIdentity extends Model
{
    /** @use HasFactory<SenderIdentityFactory> */
    use BelongsToClient, HasFactory;

    /**
     * Verification belongs to an address at a provider, so changing either clears it. Moving between a
     * sandbox and a live provider is refused while email is waiting, so it is sent where it was meant to go.
     */
    protected static function booted(): void
    {
        static::updating(function (SenderIdentity $senderIdentity): void {
            if ($senderIdentity->isDirty('smtp_provider_id')) {
                $problem = $senderIdentity->providerChangeProblem(SmtpProvider::query()->find($senderIdentity->smtp_provider_id));

                if ($problem !== null) {
                    throw new LogicException($problem);
                }
            }

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
     * @return list<string>
     */
    public function clientConsistentRelations(): array
    {
        return ['smtpProvider'];
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
     * Why this identity cannot move to the given provider. Queued email and launched campaigns would be sent
     * through whichever provider the identity has when they are sent, so the identity may not switch between
     * sandbox and live sending until they have finished or been cancelled.
     */
    public function providerChangeProblem(?SmtpProvider $provider): ?string
    {
        $current = SmtpProvider::query()->find($this->getOriginal('smtp_provider_id') ?? $this->smtp_provider_id);

        if ($provider === null || $current === null || $current->isSandbox() === $provider->isSandbox()) {
            return null;
        }

        $waitingEmails = $this->emailMessages()->whereIn('status', MessageStatus::inFlight())->count();
        $activeCampaigns = $this->campaigns()->whereIn('status', array_filter(CampaignStatus::cases(), fn (CampaignStatus $status): bool => $status->canBeCancelled()))->count();

        if ($waitingEmails === 0 && $activeCampaigns === 0) {
            return null;
        }

        return "{$this->email} has {$waitingEmails} email(s) waiting to send and {$activeCampaigns} active campaign(s). It can only move between a sandbox and a live provider once they have finished or been cancelled, so test email is never delivered for real and real email is never captured.";
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
            ->whereHas('smtpProvider', fn (Builder $provider) => $provider
                ->where('is_enabled', true)
                ->when(! config('prosend.providers.allow_sandbox'), fn (Builder $live) => $live->whereNotIn('type', array_filter(ProviderType::cases(), fn (ProviderType $type): bool => $type->isSandbox()))))
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
