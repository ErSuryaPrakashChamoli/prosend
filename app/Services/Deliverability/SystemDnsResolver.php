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
     * @return list<array<string, mixed>>
     */
    private function lookup(string $host, int $type): array
    {
        $records = @dns_get_record($host, $type);

        return is_array($records) ? array_values($records) : [];
    }
}
