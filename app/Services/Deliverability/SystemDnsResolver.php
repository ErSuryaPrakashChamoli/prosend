<?php

namespace App\Services\Deliverability;

/**
 * Looks records up through the server's DNS resolver. Results can lag behind recent DNS changes
 * because of caching, and a failed lookup is treated the same as no record.
 */
class SystemDnsResolver implements DnsResolver
{
    public function txt(string $host): array
    {
        return array_values(array_map(
            fn (array $record): string => isset($record['entries']) ? implode('', $record['entries']) : (string) ($record['txt'] ?? ''),
            $this->lookup($host, DNS_TXT),
        ));
    }

    public function cname(string $host): array
    {
        return array_values(array_map(
            fn (array $record): string => rtrim((string) ($record['target'] ?? ''), '.'),
            $this->lookup($host, DNS_CNAME),
        ));
    }

    /**
     * IPv4 goes through the system resolver, which also reads /etc/hosts, just as opening a connection does.
     */
    public function addresses(string $host): array
    {
        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = array_map(fn (array $record): string => (string) ($record['ipv6'] ?? ''), $this->lookup($host, DNS_AAAA));

        return array_values(array_unique(array_filter([...$ipv4, ...$ipv6])));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lookup(string $host, int $type): array
    {
        $records = @dns_get_record($host, $type);

        return is_array($records) ? array_values($records) : [];
    }
}
