<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings each client manages for itself: the postal address in its marketing footer, and defaults for new campaigns.
 * The built-in Admin role gets the new settings permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('postal_address')->nullable()->after('contact_email');
            $table->json('default_send_days')->nullable()->after('postal_address');
            $table->string('default_send_window_start', 5)->nullable()->after('default_send_days');
            $table->string('default_send_window_end', 5)->nullable()->after('default_send_window_start');
            $table->boolean('default_track_opens')->default(true)->after('default_send_window_end');
            $table->boolean('default_track_clicks')->default(false)->after('default_track_opens');
        });

        $this->grantNewPermissions();
    }

    public function grantNewPermissions(): void
    {
        foreach (SystemRole::cases() as $role) {
            if (! in_array(Permission::ManageSettings, $role->defaultPermissions(), true)) {
                continue;
            }

            $row = DB::table('roles')->where('key', $role->value)->first();

            if ($row !== null) {
                DB::table('roles')->where('id', $row->id)->update([
                    'permissions' => json_encode(array_values(array_unique([...(json_decode((string) $row->permissions, true) ?: []), Permission::ManageSettings->value]))),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['postal_address', 'default_send_days', 'default_send_window_start', 'default_send_window_end', 'default_track_opens', 'default_track_clicks']);
        });

        foreach (DB::table('roles')->get() as $row) {
            DB::table('roles')->where('id', $row->id)->update([
                'permissions' => json_encode(array_values(array_diff(json_decode((string) $row->permissions, true) ?: [], [Permission::ManageSettings->value]))),
            ]);
        }
    }
};
