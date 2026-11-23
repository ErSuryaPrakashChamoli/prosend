<?php

namespace App\Observers;

use App\Models\ClientMembership;
use App\Models\Team;
use App\Services\AuditLogger;

/**
 * Records every change to who can work in a client, whether it came from an invitation, the client's member list or
 * a Super Admin editing the person.
 */
class ClientMembershipObserver
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function created(ClientMembership $membership): void
    {
        $this->auditLogger->record('member.added', $membership, [
            'email' => $membership->user->email,
            'role' => $membership->role->name,
        ], client: $membership->client);
    }

    public function updated(ClientMembership $membership): void
    {
        if ($membership->wasChanged('role_id')) {
            $this->auditLogger->record('member.role_changed', $membership, [
                'email' => $membership->user->email,
                'role' => $membership->role()->value('name'),
            ], client: $membership->client);
        }
    }

    public function deleted(ClientMembership $membership): void
    {
        // Someone who left the client leaves its teams too.
        foreach (Team::acrossClients()->where('client_id', $membership->client_id)->get() as $team) {
            $team->members()->detach($membership->user_id);

            if ($team->leader_id === $membership->user_id) {
                $team->forceFill(['leader_id' => null])->save();
            }
        }

        $this->auditLogger->record('member.removed', $membership, [
            'email' => $membership->user->email,
        ], client: $membership->client);
    }
}
