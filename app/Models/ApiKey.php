<?php

namespace App\Models;

use App\Enums\ApiKeyMode;
use App\Enums\ApiScope;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A server-to-server credential. The full key is `psk_<mode>_<key id>_<secret>`: the key id finds the
 * row, and only a SHA-256 hash of the secret is stored, so the key can be shown once and never again.
 */
#[Fillable(['name', 'mode', 'scopes', 'allowed_sender_identity_ids', 'rate_limit_per_minute', 'daily_send_limit', 'allowed_ips', 'expires_at'])]
#[Hidden(['secret_hash'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    public const KEY_PATTERN = '/^psk_(live|test)_([A-Za-z0-9]{12})_([A-Za-z0-9]{40})$/';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'rate_limit_per_minute' => 60,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ApiKeyMode::class,
            'allowed_sender_identity_ids' => 'array',
            'allowed_ips' => 'array',
            'rate_limit_per_minute' => 'integer',
            'daily_send_limit' => 'integer',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Scopes are stored as their string values, whether enums or strings are given.
     *
     * @return Attribute<list<string>, list<ApiScope|string>>
     */
    protected function scopes(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => json_decode($value ?? '[]', true) ?: [],
            set: fn (array $value): string => json_encode(array_values(array_unique(array_map(
                fn (ApiScope|string $scope): string => $scope instanceof ApiScope ? $scope->value : $scope,
                $value,
            )))),
        );
    }

    /**
     * Create a key and return it with its plaintext form, which is never stored and cannot be recovered.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: self, 1: string}
     */
    public static function issue(array $attributes, ?User $creator = null, ?self $rotatedFrom = null): array
    {
        $secret = Str::random(40);
        $key = new self($attributes);
        $key->forceFill([
            'key_id' => Str::random(12),
            'secret_hash' => hash('sha256', $secret),
            'last_four' => substr($secret, -4),
            'created_by' => $creator?->getKey(),
            'rotated_from_id' => $rotatedFrom?->getKey(),
        ])->save();

        return [$key, "psk_{$key->mode->value}_{$key->key_id}_{$secret}"];
    }

    /**
     * Find the key a plaintext value belongs to, comparing the secret in constant time.
     */
    public static function findByPlaintext(string $plaintext): ?self
    {
        if (preg_match(self::KEY_PATTERN, $plaintext, $parts) !== 1) {
            return null;
        }

        $key = static::query()->where('key_id', $parts[2])->first();

        if ($key === null || $key->mode->value !== $parts[1] || ! hash_equals($key->secret_hash, hash('sha256', $parts[3]))) {
            return null;
        }

        return $key;
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
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function rotatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }

    /**
     * @return HasMany<ApiKeyDailyUsage, $this>
     */
    public function dailyUsage(): HasMany
    {
        return $this->hasMany(ApiKeyDailyUsage::class);
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function maskedKey(): string
    {
        return "psk_{$this->mode->value}_{$this->key_id}_…{$this->last_four}";
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function isTest(): bool
    {
        return $this->mode === ApiKeyMode::Test;
    }

    public function hasScope(ApiScope $scope): bool
    {
        return in_array($scope->value, $this->scopes ?? [], true);
    }

    public function allowsSender(SenderIdentity $senderIdentity): bool
    {
        return in_array($senderIdentity->getKey(), array_map('intval', $this->allowed_sender_identity_ids ?? []), true);
    }

    public function allowsIp(?string $ip): bool
    {
        if (blank($this->allowed_ips)) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $this->allowed_ips);
    }

    /**
     * Record a use, writing at most once a minute so busy keys do not update the row on every request.
     */
    public function touchLastUsed(?string $ip): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subMinute()) && $this->last_used_ip === $ip) {
            return;
        }

        $this->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->saveQuietly();
    }

    /**
     * Issue a successor with the same settings. The old key keeps working for the grace period, then stops.
     *
     * @return array{0: self, 1: string}
     */
    public function rotate(int $graceDays, User $by): array
    {
        return DB::transaction(function () use ($graceDays, $by): array {
            $successor = self::issue($this->only($this->getFillable()), $by, rotatedFrom: $this);

            if ($graceDays === 0) {
                $this->forceFill(['revoked_at' => now(), 'revoked_by' => $by->getKey()])->save();
            } else {
                $graceEnds = now()->addDays($graceDays);
                $this->forceFill(['expires_at' => $this->expires_at?->lt($graceEnds) ? $this->expires_at : $graceEnds])->save();
            }

            return $successor;
        });
    }

    public function revoke(User $by): void
    {
        $this->forceFill(['revoked_at' => now(), 'revoked_by' => $by->getKey()])->save();
    }

    public function emailsQueuedToday(): int
    {
        return (int) $this->dailyUsage()->where('date', now()->toDateString())->value('emails_queued');
    }
}
