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
        Schema::create('email_message_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('smtp_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('outcome', 16)->nullable();
            $table->string('code', 32)->nullable();
            $table->text('summary')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['email_message_id', 'attempt']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_message_attempts');
    }
};
