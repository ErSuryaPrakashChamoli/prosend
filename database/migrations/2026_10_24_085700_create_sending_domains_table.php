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
        Schema::create('sending_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->foreignId('smtp_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->json('dkim_selectors')->nullable();
            $table->string('spf_status')->default('unknown');
            $table->text('spf_record')->nullable();
            $table->string('dkim_status')->default('unknown');
            $table->json('dkim_records')->nullable();
            $table->string('dmarc_status')->default('unknown');
            $table->text('dmarc_record')->nullable();
            $table->string('dmarc_policy')->nullable();
            $table->timestamp('dns_checked_at')->nullable();
            $table->boolean('provider_verified')->nullable();
            $table->boolean('provider_authenticated')->nullable();
            $table->json('provider_dns_records')->nullable();
            $table->timestamp('provider_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sending_domains');
    }
};
