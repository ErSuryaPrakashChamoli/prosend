<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * is_sandbox records whether a message belongs to sandbox or live sending, fixed the first time it is
     * processed, so a retry can never send it the other way. captured_at keeps sandbox captures apart from
     * provider acceptance. Existing messages stay null.
     */
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->boolean('is_sandbox')->nullable()->after('is_retryable');
            $table->timestamp('captured_at')->nullable()->after('accepted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropColumn(['is_sandbox', 'captured_at']);
        });
    }
};
