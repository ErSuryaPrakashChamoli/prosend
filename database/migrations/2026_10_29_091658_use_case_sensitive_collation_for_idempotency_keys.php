<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency keys are opaque strings chosen by API clients, so "Order-1" and "order-1" are different keys.
     * MySQL's default collation compares them as equal, which would replay one client request as another.
     * SQLite already compares strings exactly.
     */
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->string('key', 255)->collation('utf8mb4_bin')->change();
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->string('idempotency_key', 255)->nullable()->collation('utf8mb4_bin')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $collation = Schema::getConnection()->getConfig('collation');

        Schema::table('idempotency_keys', function (Blueprint $table) use ($collation) {
            $table->string('key', 255)->collation($collation)->change();
        });

        Schema::table('email_messages', function (Blueprint $table) use ($collation) {
            $table->string('idempotency_key', 255)->nullable()->collation($collation)->change();
        });
    }
};
