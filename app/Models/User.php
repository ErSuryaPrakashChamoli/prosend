<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Observers\UserObserver;
use App\Services\Tenancy\CurrentClient;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * A person who signs in. The users.role column only tells Super Admins (who reach every client, and manage clients,
 * roles and users) from members; a member's abilities come from the role of each client membership.
 */
#[ObservedBy(UserObserver::class)]
#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'viewer',
    ];

    /**
     * Roles by client id, loaded once per instance.
     *
     * @var array<int, Role|null>|null
     */
    private ?array $rolesByClient = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Accounts are only created by administrators (there is no public sign-up). Which clients a person reaches, and
     * what they can do there, is decided by getTenants() and their roles.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * @deprecated Use isSuperAdmin() or hasPermission().
     */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * @return HasMany<ClientMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ClientMembership::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsToMany<Client, $this>
     */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)->withPivot('role_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }

    /**
     * People with a membership in the client (or the current client). Super Admins are not members.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function memberOf(Builder $query, Client|int|null $client = null): void
    {
        $clientId = $client instanceof Client ? $client->getKey() : ($client ?? app(CurrentClient::class)->id());

        $query->whereHas('memberships', fn (Builder $memberships) => $memberships->where('client_id', $clientId));
    }

    /**
     * Super Admins see every client, active or not; members see the active clients they belong to.
     *
     * @return Collection<int, Client>
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->isSuperAdmin()) {
            return Client::query()->orderBy('name')->get();
        }

        return $this->clients()->active()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Client) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        return $tenant->is_active && $this->roleIn($tenant) !== null;
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->getTenants($panel)->first();
    }

    /**
     * The person's role in the client, or null without a membership. Super Admins have no membership role.
     */
    public function roleIn(Client|int $client): ?Role
    {
        $clientId = $client instanceof Client ? $client->getKey() : $client;

        // Memberships of deactivated clients grant nothing, whether the client is passed as a model or an id.
        $this->rolesByClient ??= $this->memberships()->with(['role', 'client'])->get()
            ->filter(fn (ClientMembership $membership): bool => $membership->client?->is_active === true)
            ->mapWithKeys(fn (ClientMembership $membership): array => [$membership->client_id => $membership->role])
            ->all();

        return $this->rolesByClient[$clientId] ?? null;
    }

    /**
     * Whether the person may do something in the given client, or in the current client when none is given. Without
     * a client only Super Admins have permissions.
     */
    public function hasPermission(Permission $permission, Client|int|null $client = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $client ??= app(CurrentClient::class)->get();

        if ($client === null) {
            return false;
        }

        if ($client instanceof Client && ! $client->is_active) {
            return false;
        }

        return $this->roleIn($client)?->grants($permission) ?? false;
    }

    /**
     * Whether the person may give someone this role in the client: only roles that grant nothing beyond their own, so
     * nobody can make someone else more powerful than themselves.
     */
    public function canAssignRole(Role $role, Client|int|null $client = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $own = $this->roleIn($client ?? app(CurrentClient::class)->id() ?? 0);

        return $own !== null && array_diff($role->permissions ?? [], $own->permissions ?? []) === [];
    }

    /**
     * Forget cached roles, after memberships change.
     */
    public function refreshRoles(): static
    {
        $this->rolesByClient = null;

        return $this;
    }
}
