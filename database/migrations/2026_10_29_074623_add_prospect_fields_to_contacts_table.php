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
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('job_title')->nullable()->after('company');
            $table->string('industry')->nullable()->after('job_title');
            $table->string('website')->nullable()->after('industry');
            $table->string('stage', 20)->default('new')->after('source');
            $table->foreignId('owner_id')->nullable()->after('stage')->constrained('users')->nullOnDelete();
            $table->timestamp('last_contacted_at')->nullable()->after('unsubscribed_at');
            $table->timestamp('last_replied_at')->nullable()->after('last_contacted_at');
            $table->text('notes')->nullable()->after('last_replied_at');

            $table->index('stage');
            $table->index('industry');
        });

        $this->markEmailedContactsAsContacted();
    }

    /**
     * Contacts already emailed from a campaign or Compose start as Contacted, at their latest acceptance.
     */
    public function markEmailedContactsAsContacted(): void
    {
        $outreach = fn () => DB::table('email_messages')
            ->whereColumn('email_messages.contact_id', 'contacts.id')
            ->whereRaw("email_messages.source in ('campaign', 'compose')")
            ->whereNotNull('email_messages.accepted_at');

        DB::table('contacts')
            ->whereExists($outreach())
            ->update([
                'stage' => 'contacted',
                'last_contacted_at' => DB::raw('('.$outreach()->selectRaw('max(email_messages.accepted_at)')->toSql().')'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['stage']);
            $table->dropIndex(['industry']);
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['job_title', 'industry', 'website', 'stage', 'last_contacted_at', 'last_replied_at', 'notes']);
        });
    }
};
