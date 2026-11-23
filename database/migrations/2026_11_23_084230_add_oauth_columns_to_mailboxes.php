<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gmail and Microsoft 365 mailboxes connected with OAuth: the provider keeps the (encrypted) tokens, and its inbound
 * mailbox reads replies through the same connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smtp_providers', function (Blueprint $table) {
            $table->string('oauth_account_email')->nullable()->after('api_key');
            $table->text('oauth_access_token')->nullable()->after('oauth_account_email');
            $table->text('oauth_refresh_token')->nullable()->after('oauth_access_token');
            $table->timestamp('oauth_expires_at')->nullable()->after('oauth_refresh_token');
        });

        Schema::table('inbound_mailboxes', function (Blueprint $table) {
            $table->foreignId('smtp_provider_id')->nullable()->after('client_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_mailboxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('smtp_provider_id');
        });

        Schema::table('smtp_providers', function (Blueprint $table) {
            $table->dropColumn(['oauth_account_email', 'oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at']);
        });
    }
};
