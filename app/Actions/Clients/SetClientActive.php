<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Services\AuditLogger;

/**
 * Deactivating a client stops its members and API keys at their next request; nothing is deleted.
 */
class SetClientActive
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function handle(Client $client, bool $active): void
    {
        if ($client->is_active === $active) {
            return;
        }

        $active ? $client->activate() : $client->deactivate();

        $this->auditLogger->record($active ? 'client.activated' : 'client.deactivated', $client, ['name' => $client->name]);
    }
}
