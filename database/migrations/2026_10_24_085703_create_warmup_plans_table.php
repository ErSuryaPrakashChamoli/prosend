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
        Schema::create('warmup_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('smtp_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sending_domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');
            $table->date('start_date');
            $table->unsignedInteger('initial_daily_volume');
            $table->unsignedInteger('target_daily_volume');
            $table->unsignedInteger('max_daily_volume')->nullable();
            $table->unsignedInteger('max_per_minute')->nullable();
            $table->unsignedSmallInteger('ramp_interval_days')->default(1);
            $table->unsignedSmallInteger('growth_percent')->default(30);
            $table->boolean('business_days_only')->default(false);
            $table->time('sending_window_start')->nullable();
            $table->time('sending_window_end')->nullable();
            $table->decimal('max_hard_bounce_rate', 5, 2)->default(2.00);
            $table->decimal('max_complaint_rate', 5, 3)->default(0.100);
            $table->unsignedInteger('current_daily_volume')->nullable();
            $table->timestamp('last_increased_at')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->string('last_recommendation')->nullable();
            $table->text('paused_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['smtp_provider_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warmup_plans');
    }
};
