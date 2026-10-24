<?php

namespace App\Models;

use App\Enums\ProviderHealth;
use App\Enums\ProviderType;
use App\Enums\SmtpEncryption;
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

#[ObservedBy(SmtpProviderObserver::class)]
#[Fillable(['name', 'type', 'host', 'port', 'encryption', 'username', 'password', 'api_key', 'daily_limit', 'monthly_limit', 'max_per_minute', 'priority', 'is_enabled'])]
#[Hidden(['password', 'api_key', 'webhook_token_hash'])]
class SmtpProvider extends Model
{
    /** @use HasFactory<SmtpProviderFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'health_status' => 'unknown',
        'is_enabled' => true,
        'priority' => 100,
    ];

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
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function enabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    public function acceptedCountSince(CarbonInterface $since): int
    {
        return $this->emailMessages()->where('accepted_at', '>=', $since)->count();
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
