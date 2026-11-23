<?php

namespace App\Services\Tenancy;

use App\Models\Client;
use Filament\Facades\Filament;

/**
 * The client whose data the current request or job works with. In the panel it is the Filament tenant; the API, public
 * mail routes and queue jobs set it from the API key or the record they handle. Without a current client, queries are
 * not scoped (scheduled commands deliberately work across clients), so code reached from user input must set one.
 *
 * Registered as a scoped singleton, so it is reset between queue jobs.
 */
class CurrentClient
{
    private ?Client $client = null;

    public function set(?Client $client): void
    {
        $this->client = $client;
    }

    public function get(): ?Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $tenant = Filament::getTenant();

        return $tenant instanceof Client ? $tenant : null;
    }

    public function id(): ?int
    {
        return $this->get()?->getKey();
    }

    /**
     * Run the callback with the given client as the current one, then restore the previous client.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function runAs(Client $client, callable $callback): mixed
    {
        $previous = $this->client;
        $this->client = $client;

        try {
            return $callback();
        } finally {
            $this->client = $previous;
        }
    }

    /**
     * Run the callback as the client that owns the given client id.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function runAsClientId(int $clientId, callable $callback): mixed
    {
        $client = $this->client?->getKey() === $clientId ? $this->client : Client::query()->findOrFail($clientId);

        return $this->runAs($client, $callback);
    }
}
