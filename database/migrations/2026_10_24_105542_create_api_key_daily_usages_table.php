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
        Schema::create('api_key_daily_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('emails_queued')->default(0);
            $table->unsignedInteger('client_errors')->default(0);
            $table->unsignedInteger('server_errors')->default(0);
            $table->timestamps();

            $table->unique(['api_key_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_key_daily_usages');
    }
};
