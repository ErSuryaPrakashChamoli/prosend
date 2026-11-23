<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automation rules (when something happens to a prospect, if conditions match, run actions), tasks for the team, and
 * signed outgoing webhooks. The built-in roles get the new automation permission.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    public const NEW_PERMISSIONS = ['automations.manage', 'tasks.view', 'tasks.manage'];

    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('trigger', 40);
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'trigger', 'is_active']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 40);
            $table->string('dedupe_key', 100);
            $table->string('status', 20);
            $table->json('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['automation_rule_id', 'dedupe_key']);
            $table->index(['client_id', 'created_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'completed_at', 'due_at']);
            $table->index(['client_id', 'assigned_to']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('url', 2048);
            $table->string('description')->nullable();
            $table->json('events');
            $table->text('secret');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('event', 40);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'created_at']);
            $table->index(['status', 'next_attempt_at']);
        });

        $this->grantNewPermissions();
    }

    public function grantNewPermissions(): void
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
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');

        foreach (DB::table('roles')->get() as $row) {
            DB::table('roles')->where('id', $row->id)->update([
                'permissions' => json_encode(array_values(array_diff(json_decode((string) $row->permissions, true) ?: [], self::NEW_PERMISSIONS))),
            ]);
        }
    }
};
