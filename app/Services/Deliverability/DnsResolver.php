<?php

namespace App\Services\Deliverability;

interface DnsResolver
{
    /**
     * TXT record values for the host, with multi-part strings joined.
     *
     * @return list<string>
     */
    public function txt(string $host): array;

    /**
     * CNAME targets for the host.
     *
     * @return list<string>
     */
    public function cname(string $host): array;

    /**
     * IPv4 and IPv6 addresses the host resolves to, as the system would connect to them.
     *
     * @return list<string>
     */
    public function addresses(string $host): array;
}
