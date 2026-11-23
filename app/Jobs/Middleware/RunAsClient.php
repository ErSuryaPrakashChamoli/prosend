<?php

namespace App\Jobs\Middleware;

use App\Services\Tenancy\CurrentClient;
use Closure;

/**
 * Runs a queued job as the given client, so every query inside it is limited to that client's data.
 */
class RunAsClient
{
    public function __construct(public int $clientId) {}

    public function handle(object $job, Closure $next): mixed
    {
        return app(CurrentClient::class)->runAsClientId($this->clientId, fn (): mixed => $next($job));
    }
}
