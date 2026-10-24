<?php

use App\Enums\DnsCheckStatus;
use App\Enums\ProviderType;
use App\Models\SendingDomain;
use App\Models\SmtpProvider;
use App\Services\Deliverability\DomainAuthenticator;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    Http::preventStrayRequests();
});

function brevoDomain(array $attributes = []): SendingDomain
{
    return SendingDomain::factory()->for(SmtpProvider::factory()->state(['api_key' => null]))->create(['domain' => 'acme.test', ...$attributes]);
}

it('finds SPF, both Brevo DKIM records, and the DMARC policy', function () {
    FakeDnsResolver::install(
        txt: [
            'acme.test' => ['google-site-verification=abc', 'v=spf1 include:spf.brevo.com ~all'],
            '_dmarc.acme.test' => ['v=DMARC1; p=quarantine; rua=mailto:dmarc@acme.test'],
        ],
        cname: [
            'brevo1._domainkey.acme.test' => ['b1.acme-test.dkim.brevo.com'],
            'brevo2._domainkey.acme.test' => ['b2.acme-test.dkim.brevo.com'],
        ],
    );
    $domain = brevoDomain();

    app(DomainAuthenticator::class)->checkDns($domain);

    expect($domain->refresh())
        ->spf_status->toBe(DnsCheckStatus::Found)
        ->spf_record->toBe('v=spf1 include:spf.brevo.com ~all')
        ->dkim_status->toBe(DnsCheckStatus::Found)
        ->dkim_records->toBe(['brevo1' => 'CNAME b1.acme-test.dkim.brevo.com', 'brevo2' => 'CNAME b2.acme-test.dkim.brevo.com'])
        ->dmarc_status->toBe(DnsCheckStatus::Found)
        ->dmarc_policy->toBe('quarantine')
        ->dns_checked_at->not->toBeNull();
});

it('reports problems with SPF, DKIM, and DMARC records', function (array $txt, array $cname, string $field, DnsCheckStatus $expected) {
    FakeDnsResolver::install($txt, $cname);
    $domain = brevoDomain();

    app(DomainAuthenticator::class)->checkDns($domain);

    expect($domain->refresh()->{$field})->toBe($expected);
})->with([
    'no SPF record' => [[], [], 'spf_status', DnsCheckStatus::Missing],
    'two SPF records' => [['acme.test' => ['v=spf1 -all', 'v=spf1 include:spf.brevo.com -all']], [], 'spf_status', DnsCheckStatus::Invalid],
    'SPF allowing everyone' => [['acme.test' => ['v=spf1 include:spf.brevo.com +all']], [], 'spf_status', DnsCheckStatus::Invalid],
    'SPF without Brevo' => [['acme.test' => ['v=spf1 include:_spf.google.com ~all']], [], 'spf_status', DnsCheckStatus::Incomplete],
    'one of two DKIM CNAMEs' => [[], ['brevo1._domainkey.acme.test' => ['b1.dkim.brevo.com']], 'dkim_status', DnsCheckStatus::Incomplete],
    'legacy DKIM TXT' => [['mail._domainkey.acme.test' => ['k=rsa; p=MIGfMA0GCSq']], [], 'dkim_status', DnsCheckStatus::Found],
    'no DKIM' => [[], [], 'dkim_status', DnsCheckStatus::Missing],
    'no DMARC' => [[], [], 'dmarc_status', DnsCheckStatus::Missing],
    'DMARC without a policy' => [['_dmarc.acme.test' => ['v=DMARC1; rua=mailto:x@acme.test']], [], 'dmarc_status', DnsCheckStatus::Invalid],
]);

it('records Brevo\'s own verification and the records it expects', function () {
    FakeDnsResolver::install();
    Http::fake(['https://api.brevo.com/v3/senders/domains/acme.test' => Http::response([
        'domain' => 'acme.test',
        'verified' => true,
        'authenticated' => false,
        'dns_records' => ['dkim_record' => ['type' => 'TXT', 'host_name' => 'mail._domainkey', 'value' => 'k=rsa;p=abc', 'status' => false]],
    ])]);
    $domain = brevoDomain();
    $domain->smtpProvider->update(['api_key' => 'xkeysib-test']);

    $problem = app(DomainAuthenticator::class)->checkProvider($domain);

    expect($problem)->toBeNull();
    expect($domain->refresh())
        ->provider_verified->toBeTrue()
        ->provider_authenticated->toBeFalse()
        ->provider_dns_records->toHaveKey('dkim_record');
    Http::assertSent(fn ($request): bool => $request->hasHeader('api-key', 'xkeysib-test'));
});

it('explains when the domain is not added in Brevo', function () {
    Http::fake(['https://api.brevo.com/v3/senders/domains/*' => Http::response(['code' => 'document_not_found'], 404)]);
    $domain = brevoDomain();
    $domain->smtpProvider->update(['api_key' => 'xkeysib-test']);

    app(DomainAuthenticator::class)->checkProvider($domain);

    expect($domain->refresh())
        ->provider_verified->toBeFalse()
        ->last_error->toContain('acme.test has not been added in Brevo yet');
});

it('reports a rejected API key without changing the saved verdict', function () {
    Http::fake(['https://api.brevo.com/*' => Http::response(['message' => 'Key not found'], 401)]);
    $domain = brevoDomain(['provider_authenticated' => true]);
    $domain->smtpProvider->update(['api_key' => 'wrong']);

    $problem = app(DomainAuthenticator::class)->checkProvider($domain);

    expect($problem)->toBe('Brevo rejected the API key. Check it under Infrastructure > SMTP providers.');
    expect($domain->refresh()->provider_authenticated)->toBeTrue();
});

it('skips the provider check without an API key', function () {
    $domain = brevoDomain();

    expect(app(DomainAuthenticator::class)->checkProvider($domain))->toBeNull();

    Http::assertNothingSent();
});

it('is only ready when the provider authenticated it and DKIM and DMARC are present', function () {
    $domain = SendingDomain::factory()->make([
        'dkim_status' => DnsCheckStatus::Found,
        'dmarc_status' => DnsCheckStatus::Found,
        'dns_checked_at' => now(),
        'provider_verified' => true,
        'provider_authenticated' => false,
    ]);

    expect($domain->isReady())->toBeFalse();

    $domain->provider_authenticated = true;

    expect($domain->isReady())->toBeTrue();
});

it('uses the Brevo DKIM selectors unless others are configured', function () {
    $brevo = SendingDomain::factory()->for(SmtpProvider::factory())->make();
    $generic = SendingDomain::factory()->for(SmtpProvider::factory()->state(['type' => ProviderType::Smtp]))->make(['dkim_selectors' => ['s1']]);

    expect($brevo->selectors())->toBe(['brevo1', 'brevo2', 'mail'])
        ->and($generic->selectors())->toBe(['s1']);
});

it('is not ready when the provider check failed, even if DNS looks right', function () {
    Http::fake(['https://api.brevo.com/*' => Http::response(['message' => 'Internal error'], 500)]);
    FakeDnsResolver::install(
        txt: ['_dmarc.acme.test' => ['v=DMARC1; p=none']],
        cname: ['brevo1._domainkey.acme.test' => ['b1.dkim.brevo.com'], 'brevo2._domainkey.acme.test' => ['b2.dkim.brevo.com']],
    );
    $domain = brevoDomain();
    $domain->smtpProvider->update(['api_key' => 'xkeysib-test']);

    app(DomainAuthenticator::class)->check($domain);

    expect($domain->refresh()->isReady())->toBeFalse();
    expect($domain->readinessIssues()[0])->toStartWith('The provider could not confirm the domain');
});

it('discards earlier results when the domain is renamed', function () {
    $domain = brevoDomain(['dkim_status' => DnsCheckStatus::Found, 'dmarc_status' => DnsCheckStatus::Found, 'dns_checked_at' => now(), 'provider_authenticated' => true]);

    $domain->update(['domain' => 'brand-new.test']);

    expect($domain->refresh())
        ->dkim_status->toBe(DnsCheckStatus::Unknown)
        ->provider_authenticated->toBeNull()
        ->isReady()->toBeFalse();
});
