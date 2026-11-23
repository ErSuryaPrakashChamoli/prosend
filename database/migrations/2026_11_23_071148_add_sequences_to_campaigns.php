<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns become sequences: the campaign's own content is the first email, and follow-up steps go out after a wait
 * unless the prospect replied, unsubscribed or bounced. Each prospect's place in the sequence is kept in
 * campaign_prospects, with a log of what happened to them in campaign_prospect_events. Sending windows and open/click tracking are off for existing campaigns, so they behave as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('wait_days')->default(2);
            $table->string('condition', 20)->default('no_reply');
            $table->string('subject')->nullable();
            $table->longText('body');
            $table->longText('text_body')->nullable();
            $table->timestamps();

            // Not unique: the form's repeater saves reordered steps one row at a time.
            $table->index(['campaign_id', 'position']);
        });

        Schema::create('campaign_prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->timestamp('next_step_due_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('finish_reason')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['status', 'next_step_due_at']);
            $table->index(['contact_id', 'status']);
        });

        Schema::create('campaign_prospect_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_prospect_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->unsignedSmallInteger('step_number')->nullable();
            $table->string('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['campaign_prospect_id', 'id']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('send_days')->nullable()->after('send_rate_per_minute');
            $table->string('send_window_start', 5)->nullable()->after('send_days');
            $table->string('send_window_end', 5)->nullable()->after('send_window_start');
            $table->string('timezone', 64)->nullable()->after('send_window_end');
            $table->boolean('use_prospect_timezone')->default(false)->after('timezone');
            $table->boolean('track_opens')->default(false)->after('use_prospect_timezone');
            $table->boolean('track_clicks')->default(false)->after('track_opens');
            $table->timestamp('archived_at')->nullable()->after('completed_at');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('website');
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->foreignId('campaign_step_id')->nullable()->after('campaign_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('step_number')->default(1)->after('campaign_step_id');
        });

        // The new index is added before the old one is dropped, so MySQL always has an index for the campaign_id key.
        Schema::table('email_messages', function (Blueprint $table) {
            $table->unique(['campaign_id', 'contact_id', 'step_number']);
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'contact_id']);
        });
    }

    /**
     * Follow-up emails cannot be kept without sequences: the old one-email-per-recipient index would not hold. Rolling
     * back is refused while any exist, rather than deleting sent-email records.
     */
    public function down(): void
    {
        if (DB::table('email_messages')->where('step_number', '>', 1)->exists()) {
            throw new RuntimeException('Follow-up emails exist. Rolling back sequences would require deleting their records, so it was refused.');
        }

        Schema::table('email_messages', function (Blueprint $table) {
            $table->unique(['campaign_id', 'contact_id']);
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'contact_id', 'step_number']);
            $table->dropConstrainedForeignId('campaign_step_id');
            $table->dropColumn('step_number');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['send_days', 'send_window_start', 'send_window_end', 'timezone', 'use_prospect_timezone', 'track_opens', 'track_clicks', 'archived_at']);
        });

        Schema::dropIfExists('campaign_prospect_events');
        Schema::dropIfExists('campaign_prospects');
        Schema::dropIfExists('campaign_steps');
    }
};
