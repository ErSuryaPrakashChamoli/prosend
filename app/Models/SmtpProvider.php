<?php

namespace App\Models;

use App\Enums\ProviderHealth;
use App\Enums\ProviderType;
use App\Enums\SmtpEncryption;
use App\Models\Concerns\BelongsToClient;
use App\Observers\SmtpProviderObserver;
use Carbon\CarbonInterface;
use Database\Factories\SmtpProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

#[ObservedBy(SmtpProviderObserver::class)]
#[Fillable(['name', 'type', 'host', 'port', 'encryption', 'username', 'password', 'api_key', 'daily_limit', 'monthly_limit', 'max_per_minute', 'priority', 'is_enabled'])]
#[Hidden(['password', 'api_key', 'webhook_token_hash', 'oauth_access_token', 'oauth_refresh_token'])]
class SmtpProvider extends Model
{
    /** @use HasFactory<SmtpProviderFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'health_status' => 'unknown',
        'is_enabled' => true,
        'priority' => 100,
    ];

    /**
     * A provider never switches between sandbox and live sending, so email queued for one can never be
     * handed to the other. Create a new provider instead.
     */
    protected static function booted(): void
    {
        static::updating(function (SmtpProvider $provider): void {
            $original = ProviderType::tryFrom((string) $provider->getRawOriginal('type'));

            if ($provider->isDirty('type') && $original !== null && $original->isSandbox() !== $provider->type->isSandbox()) {
                throw new LogicException('A provider cannot switch between sandbox and live sending. Create a new provider instead.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'encryption' => SmtpEncryption::class,
            'password' => 'encrypted',
            'api_key' => 'encrypted',
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'oauth_expires_at' => 'datetime',
            'port' => 'integer',
            'daily_limit' => 'integer',
            'monthly_limit' => 'integer',
            'max_per_minute' => 'integer',
            'priority' => 'integer',
            'is_enabled' => 'boolean',
            'health_status' => ProviderHealth::class,
            'last_connected_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SenderIdentity, $this>
     */
    public function senderIdentities(): HasMany
    {
        return $this->hasMany(SenderIdentity::class);
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * @return HasMany<EmailMessageAttempt, $this>
     */
    public function sendAttempts(): HasMany
    {
        return $this->hasMany(EmailMessageAttempt::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function enabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    /**
     * Whether email sent through this provider is captured for inspection instead of delivered.
     */
    public function isSandbox(): bool
    {
        return $this->type->isSandbox();
    }

    /**
     * Why this provider may not send in this environment, when it is a sandbox and sandboxes are turned off.
     */
    public function sandboxRefusal(): ?string
    {
        return $this->isSandbox() && ! config('prosend.providers.allow_sandbox')
            ? "The provider {$this->name} is a sandbox, and sandbox providers are turned off in this environment (PROSEND_ALLOW_SANDBOX_PROVIDERS)."
            : null;
    }

    /**
     * Why this provider may not send right now: a sandbox where sandboxes are turned off, or a host that
     * does not match the provider type, so sandbox and live sending can never be confused.
     */
    public function sendingRefusal(): ?string
    {
        $hostProblem = $this->type->hostProblem($this->host);

        return $this->sandboxRefusal()
            ?? ($hostProblem === null ? null : "The provider {$this->name} cannot send: {$hostProblem} Fix its host or type.");
    }

    /**
     * Email this provider took since the given time, accepted or, for a sandbox, captured. Quotas count both.
     */
    public function acceptedCountSince(CarbonInterface $since): int
    {
        return $this->emailMessages()
            ->where(fn (Builder $messages) => $messages->where('accepted_at', '>=', $since)->orWhere('captured_at', '>=', $since))
            ->count();
    }

    public function remainingDailyQuota(): ?int
    {
        if ($this->daily_limit === null) {
            return null;
        }

        return max(0, $this->daily_limit - $this->acceptedCountSince(now()->startOfDay()));
    }

    public function remainingMonthlyQuota(): ?int
    {
        if ($this->monthly_limit === null) {
            return null;
        }

        return max(0, $this->monthly_limit - $this->acceptedCountSince(now()->startOfMonth()));
    }

    /**
     * The smaller of the remaining daily and monthly quotas, or null when neither is limited.
     */
    public function remainingQuota(): ?int
    {
        $quotas = array_filter(
            [$this->remainingDailyQuota(), $this->remainingMonthlyQuota()],
            fn (?int $quota): bool => $quota !== null,
        );

        return $quotas === [] ? null : min($quotas);
    }

    public function recordConnectionSuccess(): void
    {
        $this->forceFill([
            'health_status' => ProviderHealth::Healthy,
            'last_checked_at' => now(),
            'last_connected_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function recordConnectionFailure(string $error): void
    {
        $this->forceFill([
            'health_status' => ProviderHealth::Failing,
            'last_checked_at' => now(),
            'last_error' => Str::limit($error, 1000),
        ])->save();
    }

    /**
     * Replace the webhook token. Only its hash is stored, so the returned token can be shown once.
     */
    public function regenerateWebhookToken(): string
    {
        $token = Str::random(48);

        $this->forceFill(['webhook_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function webhookTokenMatches(string $token): bool
    {
        return filled($this->webhook_token_hash)
            && hash_equals($this->webhook_token_hash, hash('sha256', $token));
    }
}
