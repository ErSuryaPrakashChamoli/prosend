<?php

namespace App\Models;

use App\Enums\DnsCheckStatus;
use App\Enums\ProviderType;
use Database\Factories\SendingDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['domain', 'smtp_provider_id', 'dkim_selectors'])]
class SendingDomain extends Model
{
    /** @use HasFactory<SendingDomainFactory> */
    use HasFactory;

    /**
     * Brevo publishes DKIM as two CNAMEs (brevo1, brevo2) for newer accounts, or one TXT record (mail) for older ones.
     *
     * @var list<string>
     */
    public const BREVO_DKIM_SELECTORS = ['brevo1', 'brevo2', 'mail'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'spf_status' => 'unknown',
        'dkim_status' => 'unknown',
        'dmarc_status' => 'unknown',
    ];

    /**
     * Results belong to a domain and provider, so changing either discards them until the next check.
     */
    protected static function booted(): void
    {
        static::updating(function (SendingDomain $domain): void {
            if ($domain->isDirty(['domain', 'smtp_provider_id', 'dkim_selectors'])) {
                $domain->forceFill([
                    'spf_status' => DnsCheckStatus::Unknown,
                    'spf_record' => null,
                    'dkim_status' => DnsCheckStatus::Unknown,
                    'dkim_records' => null,
                    'dmarc_status' => DnsCheckStatus::Unknown,
                    'dmarc_record' => null,
                    'dmarc_policy' => null,
                    'dns_checked_at' => null,
                    'provider_verified' => null,
                    'provider_authenticated' => null,
                    'provider_dns_records' => null,
                    'provider_checked_at' => null,
                    'last_error' => null,
                ]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dkim_selectors' => 'array',
            'spf_status' => DnsCheckStatus::class,
            'dkim_status' => DnsCheckStatus::class,
            'dkim_records' => 'array',
            'dmarc_status' => DnsCheckStatus::class,
            'dns_checked_at' => 'datetime',
            'provider_verified' => 'boolean',
            'provider_authenticated' => 'boolean',
            'provider_dns_records' => 'array',
            'provider_checked_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function domain(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::lower(trim(Str::after(trim($value), '@'), " .\t\n")));
    }

    /**
     * @return BelongsTo<SmtpProvider, $this>
     */
    public function smtpProvider(): BelongsTo
    {
        return $this->belongsTo(SmtpProvider::class);
    }

    /**
     * @return HasMany<WarmupPlan, $this>
     */
    public function warmupPlans(): HasMany
    {
        return $this->hasMany(WarmupPlan::class);
    }

    /**
     * @return Builder<SenderIdentity>
     */
    public function senderIdentitiesQuery(): Builder
    {
        return SenderIdentity::query()->where('email', 'like', '%@'.$this->domain);
    }

    /**
     * @return list<string>
     */
    public function selectors(): array
    {
        if (filled($this->dkim_selectors)) {
            return array_values($this->dkim_selectors);
        }

        return $this->smtpProvider?->type === ProviderType::Brevo ? self::BREVO_DKIM_SELECTORS : [];
    }

    /**
     * Whether the provider can report its own verdict on this domain through its API.
     */
    public function hasProviderCheck(): bool
    {
        return $this->smtpProvider?->type === ProviderType::Brevo && filled($this->smtpProvider->api_key);
    }

    public static function forEmail(string $email): ?self
    {
        return static::query()->where('domain', Str::lower(Str::after($email, '@')))->first();
    }

    /**
     * What still stands between the domain and authenticated sending. Empty when it is ready.
     *
     * A DNS record alone is not proof of authentication: when the provider can report its own
     * verdict, a successful, positive check is required; otherwise DKIM and DMARC records must at
     * least be present, and the page says the provider's verdict was not available.
     *
     * @return list<string>
     */
    public function readinessIssues(): array
    {
        if ($this->dns_checked_at === null && $this->provider_checked_at === null) {
            return ['The domain has not been checked yet.'];
        }

        $issues = [];

        if ($this->hasProviderCheck() && filled($this->last_error)) {
            $issues[] = 'The provider could not confirm the domain: '.$this->last_error;
        } elseif ($this->hasProviderCheck() && $this->provider_checked_at === null) {
            $issues[] = 'The domain has not been checked with the provider yet.';
        }

        if ($this->provider_verified === false) {
            $issues[] = 'The provider has not verified ownership of the domain.';
        }

        if ($this->provider_authenticated === false) {
            $issues[] = 'The provider has not authenticated the domain, so it cannot sign email with your domain yet.';
        }

        if ($this->dkim_status !== DnsCheckStatus::Found) {
            $issues[] = 'No complete DKIM record was found in DNS.';
        }

        if ($this->dmarc_status !== DnsCheckStatus::Found) {
            $issues[] = 'No valid DMARC record was found in DNS. Gmail and Yahoo require one for bulk senders.';
        }

        return $issues;
    }

    public function isReady(): bool
    {
        return $this->readinessIssues() === [];
    }
}
