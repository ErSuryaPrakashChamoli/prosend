<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Observers\RoleObserver;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A named set of permissions, given to people per client. Roles are shared by every client; the system roles cannot be
 * deleted, and the Admin role always has every permission.
 */
#[ObservedBy(RoleObserver::class)]
#[Fillable(['name', 'description', 'permissions'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Role $role): void {
            if ($role->key === SystemRole::Admin->value) {
                $role->permissions = Permission::values();
            }

            $role->permissions = array_values(array_intersect(Permission::values(), $role->permissions ?? []));
        });

        static::deleting(function (Role $role): void {
            if ($role->isSystem()) {
                throw new LogicException("The {$role->name} role is built in and cannot be deleted.");
            }

            if ($role->memberships()->exists()) {
                throw new LogicException("The {$role->name} role is still given to people. Give them another role first.");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public static function system(SystemRole $role): self
    {
        return static::query()->where('key', $role->value)->firstOrFail();
    }

    /**
     * @return HasMany<ClientMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ClientMembership::class)->withoutGlobalScopes();
    }

    public function isSystem(): bool
    {
        return $this->key !== null;
    }

    public function isAdmin(): bool
    {
        return $this->key === SystemRole::Admin->value;
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions ?? [], true);
    }
}
