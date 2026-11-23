<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The built-in roles get the new report permissions their defaults include. Custom roles get nothing new.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    public const NEW_PERMISSIONS = ['reports.view', 'reports.export'];

    public function up(): void
    {
        foreach (SystemRole::cases() as $role) {
            $grant = array_values(array_intersect(self::NEW_PERMISSIONS, array_map(fn (Permission $permission): string => $permission->value, $role->defaultPermissions())));
            $row = DB::table('roles')->where('key', $role->value)->first();

            if ($row === null || $grant === []) {
                continue;
            }

            DB::table('roles')->where('id', $row->id)->update([
                'permissions' => json_encode(array_values(array_unique([...(json_decode((string) $row->permissions, true) ?: []), ...$grant]))),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $row) {
            DB::table('roles')->where('id', $row->id)->update([
                'permissions' => json_encode(array_values(array_diff(json_decode((string) $row->permissions, true) ?: [], self::NEW_PERMISSIONS))),
            ]);
        }
    }
};
