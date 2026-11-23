<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Services\Tenancy\CurrentClient;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the client in the panel's URL the current client for the whole request, including Livewire updates, so every
 * query, new record and job dispatched from the panel belongs to it.
 */
class SetCurrentClientFromTenant
{
    public function __construct(private CurrentClient $currentClient) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Client) {
            $this->currentClient->set($tenant);
        }

        return $next($request);
    }
}
