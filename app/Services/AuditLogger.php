<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\CurrentClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(private Request $request, private CurrentClient $currentClient) {}

    /**
     * Record an administrative action. Never pass secrets in the properties.
     *
     * The entry belongs to the subject's client. Changes to clients belong to that client; changes to people and roles,
     * which span clients, belong to no client unless one is given.
     *
     * @param  array<string, mixed>  $properties
     */
    public function record(string $action, ?Model $subject = null, array $properties = [], ?User $user = null, ?Client $client = null): AuditLog
    {
        $user ??= $this->request->user();

        return AuditLog::query()->create([
            'client_id' => $client?->getKey() ?? $this->clientIdFor($subject),
            'user_id' => $user?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => $this->request->ip(),
        ]);
    }

    private function clientIdFor(?Model $subject): ?int
    {
        return match (true) {
            $subject instanceof Client => $subject->getKey(),
            $subject instanceof User, $subject instanceof Role => null,
            $subject !== null && $subject->getAttribute('client_id') !== null => (int) $subject->getAttribute('client_id'),
            default => $this->currentClient->id(),
        };
    }
}
