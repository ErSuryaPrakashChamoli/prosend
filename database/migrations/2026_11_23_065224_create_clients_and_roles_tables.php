<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clients (the agency's customers) own every prospect, campaign and mailbox. People reach a client through a
 * membership that carries a role; Super Admins (the former administrators) reach every client.
 */
return new class extends Migration
{
    public const DEFAULT_CLIENT_SLUG = 'default';

    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 60)->unique();
            $table->boolean('is_active')->default(true);
            $table->string('timezone', 64)->default('UTC');
            $table->string('website')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->nullable()->unique();
            $table->string('name', 60)->unique();
            $table->string('description')->nullable();
            $table->json('permissions');
            $table->timestamps();
        });

        Schema::create('client_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'user_id']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->foreignId('leader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'name']);
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        $this->createSystemRoles();
        $this->createDefaultClientWithMembers();
    }

    public function createSystemRoles(): void
    {
        foreach (SystemRole::cases() as $role) {
            DB::table('roles')->insert([
                'key' => $role->value,
                'name' => $role->label(),
                'description' => $role->description(),
                'permissions' => json_encode(array_map(fn (Permission $permission): string => $permission->value, $role->defaultPermissions())),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Everything that existed before clients belongs to the Default client. Senders and viewers become members of it
     * with the closest role; administrators already reach every client as Super Admins.
     */
    public function createDefaultClientWithMembers(): void
    {
        $clientId = DB::table('clients')->insertGetId([
            'name' => 'Default client',
            'slug' => self::DEFAULT_CLIENT_SLUG,
            'is_active' => true,
            'timezone' => config('app.timezone', 'UTC'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleIds = DB::table('roles')->whereNotNull('key')->pluck('id', 'key');

        DB::table('users')
            ->where('role', '!=', UserRole::Admin->value)
            ->orderBy('id')
            ->each(function (object $user) use ($clientId, $roleIds): void {
                $role = SystemRole::forLegacyRole(UserRole::tryFrom((string) $user->role) ?? UserRole::Viewer);

                DB::table('client_user')->insert([
                    'client_id' => $clientId,
                    'user_id' => $user->id,
                    'role_id' => $roleIds[$role->value],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('client_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('clients');
    }
};
