<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every client-owned table gets a required client_id, filled with the Default client for existing rows. Values that
 * were unique across the installation (a contact's email, a list name) become unique within a client.
 */
return new class extends Migration
{
    /**
     * Tables whose rows always belong to a client.
     *
     * @var list<string>
     */
    public const CLIENT_OWNED_TABLES = [
        'contacts',
        'contact_lists',
        'segments',
        'suppressions',
        'campaigns',
        'email_messages',
        'email_templates',
        'smtp_providers',
        'sender_identities',
        'sending_domains',
        'warmup_plans',
        'api_keys',
    ];

    /**
     * Global unique indexes that become unique per client: table => column.
     *
     * @var array<string, string>
     */
    public const PER_CLIENT_UNIQUE = [
        'contacts' => 'email',
        'suppressions' => 'email',
        'sender_identities' => 'email',
        'sending_domains' => 'domain',
        'contact_lists' => 'name',
        'segments' => 'name',
    ];

    public function up(): void
    {
        $defaultClientId = DB::table('clients')->where('slug', 'default')->value('id');

        // The column is made required before the foreign key exists: MySQL can refuse to change a column that a
        // foreign key already uses.
        foreach (self::CLIENT_OWNED_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('client_id')->nullable()->after('id');
            });

            DB::table($tableName)->whereNull('client_id')->update(['client_id' => $defaultClientId]);

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('client_id')->nullable(false)->change();
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete();
            });
        }

        // Audit entries about the agency itself (clients, roles, Super Admins) belong to no client.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        DB::table('audit_logs')->whereNull('client_id')->update(['client_id' => $defaultClientId]);

        foreach (self::PER_CLIENT_UNIQUE as $tableName => $column) {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropUnique([$column]);
                $table->unique(['client_id', $column]);
            });
        }

        // Activity lists filter by creation time, reports by acceptance time.
        Schema::table('email_messages', function (Blueprint $table) {
            $table->index(['client_id', 'created_at']);
            $table->index(['client_id', 'accepted_at']);
        });
    }

    /**
     * Restoring the global unique indexes fails if two clients now share a value, such as the same contact email.
     *
     * The foreign keys go first: MySQL drops the index it created for a foreign key once a composite index starting
     * with client_id exists, and then refuses to drop that composite index while the foreign key needs it.
     */
    public function down(): void
    {
        foreach ([...self::CLIENT_OWNED_TABLES, 'audit_logs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['client_id']);
            });
        }

        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'created_at']);
            $table->dropIndex(['client_id', 'accepted_at']);
        });

        foreach (self::PER_CLIENT_UNIQUE as $tableName => $column) {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropUnique(['client_id', $column]);
                $table->unique([$column]);
            });
        }

        foreach ([...self::CLIENT_OWNED_TABLES, 'audit_logs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('client_id');
            });
        }
    }
};
