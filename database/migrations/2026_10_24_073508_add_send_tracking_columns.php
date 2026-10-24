<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('prepared_at')->nullable()->after('started_at');
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->timestamp('sending_started_at')->nullable()->after('queued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('prepared_at');
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropColumn('sending_started_at');
        });
    }
};
