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
        Schema::create('smtp_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('encryption');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->unsignedInteger('daily_limit')->nullable();
            $table->unsignedInteger('monthly_limit')->nullable();
            $table->unsignedInteger('max_per_minute')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_enabled')->default(true);
            $table->string('health_status')->default('unknown');
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('webhook_token_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smtp_providers');
    }
};
