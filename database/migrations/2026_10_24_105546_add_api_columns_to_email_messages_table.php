<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('purpose');
            $table->foreignId('api_key_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
            $table->string('idempotency_key', 255)->nullable()->after('api_key_id');
            $table->json('tags')->nullable()->after('attachments');
            $table->json('metadata')->nullable()->after('tags');
            $table->text('variables')->nullable()->after('metadata');

            $table->index(['api_key_id', 'created_at']);
            $table->index(['source', 'created_at']);
            $table->index(['api_key_id', 'idempotency_key']);
        });

        // Existing rows came from campaigns or from the panel's Compose page.
        DB::table('email_messages')->whereNotNull('campaign_id')->update(['source' => 'campaign']);
        DB::table('email_messages')->whereNull('campaign_id')->update(['source' => 'compose']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex(['api_key_id', 'created_at']);
            $table->dropIndex(['source', 'created_at']);
            $table->dropIndex(['api_key_id', 'idempotency_key']);
            $table->dropConstrainedForeignId('api_key_id');
            $table->dropColumn(['source', 'idempotency_key', 'tags', 'metadata', 'variables']);
        });
    }
};
