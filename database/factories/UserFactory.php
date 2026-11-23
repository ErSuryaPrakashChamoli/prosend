<?php

namespace Database\Factories;

use App\Enums\SystemRole;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\CurrentClient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Members are added to the current client with the role matching their legacy role, unless created withoutClient():
 * sender() becomes a Campaign Manager, the default a Guest Viewer. admin() is a Super Admin, who needs no membership.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    private bool $joinsCurrentClient = true;

    private ?SystemRole $clientRole = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Runs on the factory instance that created the users, so it sees withClientRole() and withoutClient().
     *
     * @param  Collection<int, Model>  $instances
     */
    protected function callAfterCreating(Collection $instances, ?Model $parent = null): void
    {
        parent::callAfterCreating($instances, $parent);

        $client = app(CurrentClient::class)->get();

        if (! $this->joinsCurrentClient || $client === null) {
            return;
        }

        $instances
            ->filter(fn (Model $user): bool => $user instanceof User && ! $user->isSuperAdmin())
            ->each(fn (User $user) => static::addToClient($user, $client, $this->clientRole ?? SystemRole::forLegacyRole($user->role)));
    }

    public static function addToClient(User $user, Client $client, SystemRole $role): ClientMembership
    {
        $membership = ClientMembership::query()->create([
            'client_id' => $client->getKey(),
            'user_id' => $user->getKey(),
            'role_id' => Role::system($role)->getKey(),
        ]);

        $user->refreshRoles();

        return $membership;
    }

    /**
     * A Super Admin: every client, plus clients, roles and users.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * A Campaign Manager in the current client (formerly "Sender").
     */
    public function sender(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Sender,
        ]);
    }

    /**
     * A member of the current client with the given built-in role.
     */
    public function withClientRole(SystemRole $role): static
    {
        $factory = $this->newInstance();
        $factory->clientRole = $role;

        return $factory;
    }

    /**
     * A member of no client.
     */
    public function withoutClient(): static
    {
        $factory = $this->newInstance();
        $factory->joinsCurrentClient = false;

        return $factory;
    }

    /**
     * Factory states create new instances, so the client settings are carried over to each one.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $factory = parent::newInstance($arguments);
        $factory->joinsCurrentClient = $this->joinsCurrentClient;
        $factory->clientRole = $this->clientRole;

        return $factory;
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
