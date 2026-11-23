<?php

use App\Enums\ProviderType;
use App\Enums\SendFailureKind;
use App\Enums\SmtpEncryption;
use App\Models\SmtpProvider;
use App\Services\Providers\BlockedSmtpHostException;
use App\Services\Providers\BrevoDriver;
use App\Services\Providers\MailProviderManager;
use App\Services\Providers\MailtrapDriver;
use App\Services\Providers\SmtpDriver;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\Fakes\FakeDnsResolver;

/**
 * @return array{EsmtpTransport, SocketStream}
 */
function transportFor(SmtpProvider $provider): array
{
    $transport = app(MailProviderManager::class)->driver($provider)->mailer($provider)->getSymfonyTransport();

    return [$transport, $transport->getStream()];
}

beforeEach(function () {
    config(['prosend.providers.allow_private_hosts' => false]);
});

it('uses a dedicated driver for each provider type', function (ProviderType $type, string $driver) {
    expect(app(MailProviderManager::class)->driver(new SmtpProvider(['type' => $type])))->toBeInstanceOf($driver);
})->with([
    [ProviderType::Brevo, BrevoDriver::class],
    [ProviderType::Smtp, SmtpDriver::class],
    [ProviderType::Mailtrap, MailtrapDriver::class],
    [ProviderType::MailtrapSandbox, MailtrapDriver::class],
]);

it('sends Mailtrap email over STARTTLS with the api login and token, pinned to the checked address', function () {
    FakeDnsResolver::install(addresses: ['live.smtp.mailtrap.io' => ['104.18.0.1']]);
    $provider = SmtpProvider::factory()->mailtrap()->create(['password' => 'mt-token-123']);

    [$transport, $stream] = transportFor($provider);

    expect($transport)->toBeInstanceOf(EsmtpTransport::class)
        ->and($transport->getUsername())->toBe('api')
        ->and($transport->getPassword())->toBe('mt-token-123')
        ->and($transport->isTlsRequired())->toBeTrue()
        ->and($stream->isTLS())->toBeFalse()
        ->and($stream->getPort())->toBe(587)
        ->and($stream->getHost())->toBe('104.18.0.1')
        ->and($stream->getStreamOptions()['ssl']['peer_name'])->toBe('live.smtp.mailtrap.io');
});

it('uses implicit TLS on port 465 and brackets a pinned IPv6 address', function () {
    FakeDnsResolver::install(addresses: ['smtp.example.net' => ['2001:4860:4860::8888']]);
    $provider = SmtpProvider::factory()->create([
        'type' => ProviderType::Smtp,
        'host' => 'smtp.example.net',
        'port' => 465,
        'encryption' => SmtpEncryption::Tls,
    ]);

    [$transport, $stream] = transportFor($provider);

    expect($stream->isTLS())->toBeTrue()
        ->and($stream->getHost())->toBe('[2001:4860:4860::8888]')
        ->and($stream->getStreamOptions()['ssl']['peer_name'])->toBe('smtp.example.net');
});

it('connects without TLS only when encryption is turned off', function () {
    FakeDnsResolver::install(addresses: ['smtp.example.net' => ['93.184.216.34']]);
    $provider = SmtpProvider::factory()->create(['type' => ProviderType::Smtp, 'host' => 'smtp.example.net', 'port' => 25, 'encryption' => SmtpEncryption::None]);

    [$transport, $stream] = transportFor($provider);

    expect($transport->isAutoTls())->toBeFalse()
        ->and($transport->isTlsRequired())->toBeFalse()
        ->and($stream->isTLS())->toBeFalse();
});

it('refuses to connect to a host that resolves to an internal address, and retries so fixing the setting lets email go out', function () {
    FakeDnsResolver::install(addresses: ['smtp.attacker.test' => ['169.254.169.254']]);
    $provider = SmtpProvider::factory()->create(['type' => ProviderType::Smtp, 'host' => 'smtp.attacker.test']);
    $driver = app(MailProviderManager::class)->driver($provider);

    expect(fn () => $driver->testConnection($provider))->toThrow(BlockedSmtpHostException::class);

    $failure = $driver->classifyFailure(new BlockedSmtpHostException('smtp.attacker.test (169.254.169.254) is a link-local address.'), $provider);

    expect($failure->kind)->toBe(SendFailureKind::Transient)
        ->and($failure->retryable)->toBeTrue()
        ->and($failure->affectsProvider())->toBeTrue();
});

it('declares that Mailtrap delivery events are not processed', function () {
    expect(app(MailtrapDriver::class)->capabilities()->deliveryWebhooks)->toBeFalse()
        ->and(app(BrevoDriver::class)->capabilities()->deliveryWebhooks)->toBeTrue();
});
