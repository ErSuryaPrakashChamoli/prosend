<?php

namespace Tests\Fakes;

use App\Services\Deliverability\DnsResolver;

/**
 * Answers DNS lookups from a fixed table, so domain checks never touch real DNS.
 */
class FakeDnsResolver implements DnsResolver
{
    /**
     * @param  array<string, list<string>>  $txt
     * @param  array<string, list<string>>  $cname
     * @param  array<string, list<string>>  $addresses
     */
    public function __construct(
        private array $txt = [],
        private array $cname = [],
        private array $addresses = [],
    ) {}

    /**
     * @param  array<string, list<string>>  $txt
     * @param  array<string, list<string>>  $cname
     * @param  array<string, list<string>>  $addresses
     */
    public static function install(array $txt = [], array $cname = [], array $addresses = []): self
    {
        $resolver = new self($txt, $cname, $addresses);

        app()->instance(DnsResolver::class, $resolver);

        return $resolver;
    }

    public function txt(string $host): array
    {
        return $this->txt[$host] ?? [];
    }

    public function cname(string $host): array
    {
        return $this->cname[$host] ?? [];
    }

    public function addresses(string $host): array
    {
        return $this->addresses[$host] ?? [];
    }
}
