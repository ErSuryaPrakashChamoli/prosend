<?php

namespace App\Services\Providers;

use App\Services\Deliverability\DnsResolver;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Decides which addresses ProSend may open SMTP connections to, so a provider's host setting cannot be used
 * to reach internal services or cloud metadata endpoints. Every connection resolves the host again and is
 * pinned to an address checked here, so a DNS answer that changes after the provider was saved (DNS
 * rebinding) is caught as well.
 */
class SmtpHostPolicy
{
    /**
     * Never a mail server: unspecified, link-local (which includes the 169.254.169.254 metadata address),
     * IETF protocol assignments (192.0.0.0/24, which includes Oracle Cloud's 192.0.0.192), multicast,
     * reserved and broadcast addresses, deprecated site-local IPv6, local-use NAT64, and cloud metadata
     * addresses outside those ranges.
     */
    private const ALWAYS_REFUSED = [
        '0.0.0.0/8',
        '169.254.0.0/16',
        '192.0.0.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '100.100.100.200/32',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
        '64:ff9b:1::/48',
        'fd00:ec2::254/128',
    ];

    public function __construct(private DnsResolver $dns) {}

    /**
     * Why ProSend may not connect to the host, checked when a provider is saved. A host that does not
     * resolve is not refused here; testing the connection reports it.
     */
    public function problemWith(string $host, ?bool $allowPrivate = null): ?string
    {
        $host = $this->normalize($host);

        foreach ($this->addresses($host) as $address) {
            if (($refusal = $this->refusal($host, $address, $allowPrivate)) !== null) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * Resolve the host and return the address to connect to. Every answer must be allowed: a mix of public
     * and internal answers is refused, because which one a connection would use is unpredictable.
     *
     * @throws TransportException
     */
    public function addressFor(string $host, ?bool $allowPrivate = null): string
    {
        $host = $this->normalize($host);
        $addresses = $this->addresses($host);

        if ($addresses === []) {
            throw new TransportException("The SMTP host {$host} could not be resolved.");
        }

        foreach ($addresses as $address) {
            if (($refusal = $this->refusal($host, $address, $allowPrivate)) !== null) {
                throw new BlockedSmtpHostException($refusal);
            }
        }

        // Prefer IPv4, which every SMTP provider supports.
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return $addresses[0];
    }

    private function normalize(string $host): string
    {
        return rtrim(strtolower(trim($host, " []\t\n")), '.');
    }

    /**
     * @return list<string>
     */
    private function addresses(string $host): array
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->dns->addresses($host);
    }

    /**
     * $allowPrivate overrides the SMTP setting, for callers with their own (webhooks).
     */
    private function refusal(string $host, string $address, ?bool $allowPrivate = null): ?string
    {
        $ip = $this->embeddedIpv4($address) ?? $address;
        $subject = $host === $address ? $address : "{$host} ({$address})";

        // The ::/96 block holds only the unspecified address and deprecated IPv4-compatible forms, apart from ::1.
        $isUnroutableIpv6 = inet_pton($ip) !== inet_pton('::1') && IpUtils::checkIp($ip, '::/96');

        if ($isUnroutableIpv6 || IpUtils::checkIp($ip, self::ALWAYS_REFUSED)) {
            return "{$subject} is a link-local, cloud metadata, multicast or reserved address. ProSend never connects to these.";
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false || ($allowPrivate ?? config('prosend.providers.allow_private_hosts'))) {
            return null;
        }

        return "{$subject} is a private or loopback address. To allow mail servers on your own network, set PROSEND_SMTP_ALLOW_PRIVATE_HOSTS=true.";
    }

    /**
     * The IPv4 address inside an IPv4-mapped (::ffff:0:0/96), IPv4-translated (::ffff:0:0:0/96) or NAT64
     * (64:ff9b::/96) IPv6 address, which could otherwise hide an internal IPv4 address behind an IPv6 form.
     */
    private function embeddedIpv4(string $address): ?string
    {
        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $prefix = substr($packed, 0, 12);

        $embeddingPrefixes = [str_repeat("\0", 10)."\xff\xff", str_repeat("\0", 8)."\xff\xff\0\0", "\x00\x64\xff\x9b".str_repeat("\0", 8)];

        if (! in_array($prefix, $embeddingPrefixes, true)) {
            return null;
        }

        return inet_ntop(substr($packed, 12)) ?: null;
    }
}
