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
        // email_messages can be large. With foreign key checks on, MySQL adds a foreign key by copying the whole
        // table and blocking writes. The new column is empty, so there is nothing for the check to find.
        Schema::withoutForeignKeyConstraints(fn () => Schema::table('email_messages', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('purpose');
            $table->foreignId('api_key_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
            $table->string('idempotency_key', 255)->nullable()->after('api_key_id');
            $table->json('tags')->nullable()->after('attachments');
            $table->json('metadata')->nullable()->after('tags');
            $table->text('variables')->nullable()->after('metadata');

            $table->index(['api_key_id', 'created_at']);
            $table->index(['source', 'created_at']);
            $table->index(['api_key_id', 'idempotency_key']);
        }));

        // Existing rows came from campaigns, from test sends (subject "[Test] ...") or from the Compose page.
        // Batches keep each UPDATE short, so sending and webhooks are not blocked during a deploy.
        $lastId = (int) DB::table('email_messages')->max('id');

        for ($from = 1; $from <= $lastId; $from += 5000) {
            DB::table('email_messages')
                ->whereBetween('id', [$from, $from + 4999])
                ->whereNull('source')
                ->update(['source' => DB::raw("case when campaign_id is not null then 'campaign' when subject like '[Test] %' then 'test' else 'compose' end")]);
        }
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
