<?php

namespace App\Actions\Clients;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ClientInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gives a person access to a client with a role. Someone without an account gets one, with an emailed link to choose
 * their password; nobody else ever sees or sets it.
 */
class InviteClientMember
{
    public function handle(Client $client, string $email, string $name, Role $role, User $inviter): ClientMembership
    {
        $email = Str::lower(trim($email));

        // The membership is audited by ClientMembershipObserver, and a new account by UserObserver.
        [$membership, $user, $isNewAccount] = DB::transaction(function () use ($client, $email, $name, $role, $inviter): array {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $isNewAccount = $user === null;

            $problem = static::problemWithUser($client, $email, $user);

            if ($problem !== null) {
                throw ValidationException::withMessages(['email' => $problem]);
            }

            $user ??= User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(40),
                'role' => UserRole::Viewer,
            ]);

            $membership = ClientMembership::query()->create([
                'client_id' => $client->getKey(),
                'user_id' => $user->getKey(),
                'role_id' => $role->getKey(),
                'invited_by' => $inviter->getKey(),
            ]);

            return [$membership, $user, $isNewAccount];
        });

        $user->notify(new ClientInvitation(
            client: $client,
            inviterName: $inviter->name,
            roleName: $role->name,
            passwordToken: $isNewAccount ? Password::broker()->createToken($user) : null,
        ));

        return $membership;
    }

    /**
     * Why the address cannot be invited to the client, or null when it can.
     */
    public static function problemWith(Client $client, string $email): ?string
    {
        $email = Str::lower(trim($email));

        return static::problemWithUser($client, $email, User::query()->where('email', $email)->first());
    }

    private static function problemWithUser(Client $client, string $email, ?User $user): ?string
    {
        return match (true) {
            // The same answer for both, so client admins cannot tell which addresses belong to Super Admins.
            $user?->isSuperAdmin() === true,
            $user !== null && $user->memberships()->where('client_id', $client->getKey())->exists() => "{$email} already has access to {$client->name}.",
            default => null,
        };
    }
}
