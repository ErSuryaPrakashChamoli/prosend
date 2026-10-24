<?php

namespace App\Services\Deliverability;

use App\Enums\DnsCheckStatus;
use App\Enums\ProviderType;
use App\Models\SendingDomain;
use App\Services\Providers\Brevo\BrevoApiClient;
use App\Services\Providers\Brevo\BrevoApiException;
use Illuminate\Support\Str;

/**
 * Checks a sending domain in DNS and, where the provider has an API for it, with the provider.
 * DNS results only show that records exist; the provider decides whether the domain is authenticated.
 */
class DomainAuthenticator
{
    /**
     * SPF mechanisms that authorize Brevo (spf.sendinblue.com is Brevo's former name).
     *
     * @var list<string>
     */
    private const BREVO_SPF_INCLUDES = ['include:spf.brevo.com', 'include:spf.sendinblue.com'];

    public function __construct(
        private DnsResolver $dns,
        private BrevoApiClient $brevo,
    ) {}

    /**
     * Run every available check. Returns a problem that prevented the provider check, if any.
     */
    public function check(SendingDomain $domain): ?string
    {
        $this->checkDns($domain);

        return $this->checkProvider($domain);
    }

    public function checkDns(SendingDomain $domain): void
    {
        $domain->forceFill([
            ...$this->spf($domain),
            ...$this->dkim($domain),
            ...$this->dmarc($domain),
            'dns_checked_at' => now(),
        ])->save();
    }

    /**
     * Ask the provider for its own verdict. Returns why that was not possible, or null on success.
     */
    public function checkProvider(SendingDomain $domain): ?string
    {
        $provider = $domain->smtpProvider;

        if (! $domain->hasProviderCheck()) {
            return null;
        }

        try {
            $status = $this->brevo->domain($provider, $domain->domain);
        } catch (BrevoApiException $exception) {
            $domain->forceFill(['last_error' => $exception->getMessage()])->save();

            return $exception->getMessage();
        }

        $domain->forceFill([
            'provider_verified' => $status['verified'] ?? false,
            'provider_authenticated' => $status['authenticated'] ?? false,
            'provider_dns_records' => $status['dns_records'] ?? null,
            'provider_checked_at' => now(),
            'last_error' => $status === null ? "{$domain->domain} has not been added in Brevo yet (Senders, Domains & Dedicated IPs > Domains)." : null,
        ])->save();

        return null;
    }

    /**
     * @return array{spf_status: DnsCheckStatus, spf_record: ?string}
     */
    private function spf(SendingDomain $domain): array
    {
        $records = array_values(array_filter(
            $this->dns->txt($domain->domain),
            fn (string $record): bool => Str::startsWith(Str::lower(trim($record)), 'v=spf1'),
        ));

        if ($records === []) {
            return ['spf_status' => DnsCheckStatus::Missing, 'spf_record' => null];
        }

        // RFC 7208: more than one SPF record is a permanent error, so receivers ignore all of them.
        if (count($records) > 1) {
            return ['spf_status' => DnsCheckStatus::Invalid, 'spf_record' => implode("\n", $records)];
        }

        $record = $records[0];
        $mechanisms = preg_split('/\s+/', Str::lower(trim($record))) ?: [];

        if (in_array('+all', $mechanisms, true) || in_array('all', $mechanisms, true)) {
            return ['spf_status' => DnsCheckStatus::Invalid, 'spf_record' => $record];
        }

        $needsBrevo = $domain->smtpProvider?->type === ProviderType::Brevo;
        $authorizesBrevo = array_intersect(self::BREVO_SPF_INCLUDES, $mechanisms) !== [];

        return [
            'spf_status' => $needsBrevo && ! $authorizesBrevo ? DnsCheckStatus::Incomplete : DnsCheckStatus::Found,
            'spf_record' => $record,
        ];
    }

    /**
     * @return array{dkim_status: DnsCheckStatus, dkim_records: array<string, string>}
     */
    private function dkim(SendingDomain $domain): array
    {
        $found = [];

        foreach ($domain->selectors() as $selector) {
            $host = "{$selector}._domainkey.{$domain->domain}";
            $cname = $this->dns->cname($host)[0] ?? null;

            if ($cname !== null) {
                $found[$selector] = "CNAME {$cname}";

                continue;
            }

            $key = collect($this->dns->txt($host))->first(fn (string $record): bool => str_contains($record, 'p='));

            if ($key !== null) {
                $found[$selector] = 'TXT '.Str::limit($key, 120);
            }
        }

        $status = match (true) {
            $found === [] => DnsCheckStatus::Missing,
            // Brevo's CNAME setup uses a pair of selectors; one without the other is incomplete.
            isset($found['brevo1']) xor isset($found['brevo2']) => isset($found['mail']) ? DnsCheckStatus::Found : DnsCheckStatus::Incomplete,
            default => DnsCheckStatus::Found,
        };

        return ['dkim_status' => $status, 'dkim_records' => $found];
    }

    /**
     * @return array{dmarc_status: DnsCheckStatus, dmarc_record: ?string, dmarc_policy: ?string}
     */
    private function dmarc(SendingDomain $domain): array
    {
        $records = array_values(array_filter(
            $this->dns->txt("_dmarc.{$domain->domain}"),
            fn (string $record): bool => Str::startsWith(Str::lower(trim($record)), 'v=dmarc1'),
        ));

        if ($records === []) {
            return ['dmarc_status' => DnsCheckStatus::Missing, 'dmarc_record' => null, 'dmarc_policy' => null];
        }

        if (count($records) > 1) {
            return ['dmarc_status' => DnsCheckStatus::Invalid, 'dmarc_record' => implode("\n", $records), 'dmarc_policy' => null];
        }

        preg_match('/(?:^|;)\s*p\s*=\s*(none|quarantine|reject)\s*(?:;|$)/i', $records[0], $policy);

        return [
            'dmarc_status' => isset($policy[1]) ? DnsCheckStatus::Found : DnsCheckStatus::Invalid,
            'dmarc_record' => $records[0],
            'dmarc_policy' => isset($policy[1]) ? Str::lower($policy[1]) : null,
        ];
    }
}
