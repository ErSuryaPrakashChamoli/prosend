<?php

use App\Services\Providers\BlockedSmtpHostException;
use App\Services\Providers\SmtpHostPolicy;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    config(['prosend.providers.allow_private_hosts' => false]);
});

it('connects to a public mail server and prefers its IPv4 address', function () {
    FakeDnsResolver::install(addresses: ['live.smtp.mailtrap.io' => ['2606:4700::6810:1', '104.18.0.1']]);

    $policy = app(SmtpHostPolicy::class);

    expect($policy->problemWith('live.smtp.mailtrap.io'))->toBeNull()
        ->and($policy->addressFor('live.smtp.mailtrap.io'))->toBe('104.18.0.1');
});

it('always refuses link-local, cloud metadata, multicast and unspecified addresses', function (string $address) {
    config(['prosend.providers.allow_private_hosts' => true]);
    FakeDnsResolver::install(addresses: ['smtp.attacker.test' => [$address]]);

    $policy = app(SmtpHostPolicy::class);

    expect($policy->problemWith('smtp.attacker.test'))->toContain('ProSend never connects to these');
    expect(fn () => $policy->addressFor('smtp.attacker.test'))->toThrow(BlockedSmtpHostException::class);
})->with([
    'AWS, GCP and Azure metadata' => '169.254.169.254',
    'AWS metadata over IPv6' => 'fd00:ec2::254',
    'Alibaba metadata' => '100.100.100.200',
    'IPv4-mapped metadata' => '::ffff:169.254.169.254',
    'NAT64 metadata' => '64:ff9b::a9fe:a9fe',
    'IPv6 link-local' => 'fe80::1',
    'multicast' => '224.0.0.1',
    'unspecified' => '0.0.0.0',
    'IPv4-compatible IPv6' => '::a00:1',
    'Oracle Cloud metadata' => '192.0.0.192',
    'IPv4-translated metadata' => '::ffff:0:a9fe:a9fe',
    'local-use NAT64' => '64:ff9b:1::a9fe:a9fe',
    'IPv6 site-local' => 'fec0::1',
]);

it('refuses private and loopback addresses unless they are explicitly allowed', function (string $address) {
    FakeDnsResolver::install(addresses: ['relay.internal' => [$address]]);

    expect(app(SmtpHostPolicy::class)->problemWith('relay.internal'))->toContain('PROSEND_SMTP_ALLOW_PRIVATE_HOSTS');

    config(['prosend.providers.allow_private_hosts' => true]);

    expect(app(SmtpHostPolicy::class)->problemWith('relay.internal'))->toBeNull()
        ->and(app(SmtpHostPolicy::class)->addressFor('relay.internal'))->toBe($address);
})->with(['10.0.0.5', '172.16.4.2', '192.168.1.10', '127.0.0.1', '100.64.0.7', '::1', 'fd12:3456::1', '::ffff:10.0.0.5']);

it('checks an IP address entered as the host without a DNS lookup', function () {
    $policy = app(SmtpHostPolicy::class);

    expect($policy->problemWith('127.0.0.1'))->toBe('127.0.0.1 is a private or loopback address. To allow mail servers on your own network, set PROSEND_SMTP_ALLOW_PRIVATE_HOSTS=true.')
        ->and($policy->problemWith('8.8.8.8'))->toBeNull();
});

it('refuses a host when any of its addresses is internal, since the connection could use either', function () {
    FakeDnsResolver::install(addresses: ['smtp.rebind.test' => ['93.184.216.34', '10.0.0.5']]);

    expect(fn () => app(SmtpHostPolicy::class)->addressFor('smtp.rebind.test'))
        ->toThrow(BlockedSmtpHostException::class, 'smtp.rebind.test (10.0.0.5) is a private or loopback address');
});

it('checks the answer DNS gives at connection time, not the one it gave when the provider was saved', function () {
    FakeDnsResolver::install(addresses: ['smtp.rebind.test' => ['93.184.216.34']]);

    expect(app(SmtpHostPolicy::class)->problemWith('smtp.rebind.test'))->toBeNull();

    FakeDnsResolver::install(addresses: ['smtp.rebind.test' => ['169.254.169.254']]);

    expect(fn () => app(SmtpHostPolicy::class)->addressFor('smtp.rebind.test'))->toThrow(BlockedSmtpHostException::class);
});

it('lets an unresolvable host be saved but reports it as a temporary failure when connecting', function () {
    $policy = app(SmtpHostPolicy::class);

    expect($policy->problemWith('smtp.typo.test'))->toBeNull();

    try {
        $policy->addressFor('smtp.typo.test');
        $this->fail('Expected the lookup to fail.');
    } catch (TransportException $exception) {
        expect($exception)->not->toBeInstanceOf(BlockedSmtpHostException::class)
            ->and($exception->getMessage())->toBe('The SMTP host smtp.typo.test could not be resolved.');
    }
});

it('treats a host with a trailing dot like the same host without it', function () {
    FakeDnsResolver::install(addresses: ['relay.internal' => ['10.0.0.5']]);

    expect(app(SmtpHostPolicy::class)->problemWith('relay.internal.'))->toContain('relay.internal (10.0.0.5) is a private or loopback address');
});
