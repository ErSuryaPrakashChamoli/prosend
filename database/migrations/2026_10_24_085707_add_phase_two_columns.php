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
            $table->foreignId('email_template_id')->nullable()->after('sender_identity_id')->constrained()->nullOnDelete();
            $table->foreignId('segment_id')->nullable()->after('audience_type')->constrained()->nullOnDelete();
            $table->longText('text_body')->nullable()->after('body');
            $table->json('required_variables')->nullable()->after('text_body');
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->longText('text_body')->nullable()->after('html_body');
            $table->foreignId('email_template_id')->nullable()->after('sender_identity_id')->constrained()->nullOnDelete();
        });

        Schema::table('smtp_providers', function (Blueprint $table) {
            $table->text('api_key')->nullable()->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_template_id');
            $table->dropConstrainedForeignId('segment_id');
            $table->dropColumn(['text_body', 'required_variables']);
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_template_id');
            $table->dropColumn('text_body');
        });

        Schema::table('smtp_providers', function (Blueprint $table) {
            $table->dropColumn('api_key');
        });
    }
};
