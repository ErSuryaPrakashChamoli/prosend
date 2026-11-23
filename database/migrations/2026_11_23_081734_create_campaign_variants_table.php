<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A/B tests: alternatives to a campaign's first email or to a follow-up step. The original content is version A; each
 * row here is version B, C, … with its share of the traffic. Each email records which version it carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_step_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedTinyInteger('weight')->default(50);
            $table->string('subject')->nullable();
            $table->longText('body');
            $table->longText('text_body')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['campaign_id', 'campaign_step_id', 'position']);
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->foreignId('campaign_variant_id')->nullable()->after('step_number')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_variant_id');
        });

        Schema::dropIfExists('campaign_variants');
    }
};
