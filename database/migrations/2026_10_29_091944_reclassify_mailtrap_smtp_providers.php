<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Before Mailtrap had its own provider types, prosend:setup imported any non-Brevo host as generic SMTP,
     * so a Mailtrap sandbox could be labelled as live sending. Give such providers the type their host
     * implies. Model events are bypassed on purpose: the model refuses sandbox/live switches, and this is
     * a correction of the label, not a switch. Each change is written to the audit log.
     */
    public function up(): void
    {
        $types = [
            'sandbox.smtp.mailtrap.io' => 'mailtrap_sandbox',
            'live.smtp.mailtrap.io' => 'mailtrap',
            'bulk.smtp.mailtrap.io' => 'mailtrap',
        ];

        DB::table('smtp_providers')
            ->whereIn('type', ['smtp', 'brevo'])
            ->orderBy('id')
            ->get(['id', 'name', 'type', 'host'])
            ->each(function (object $provider) use ($types): void {
                $type = $types[rtrim(strtolower(trim($provider->host)), '.')] ?? null;

                if ($type === null) {
                    return;
                }

                DB::table('smtp_providers')->where('id', $provider->id)->update(['type' => $type, 'updated_at' => now()]);

                DB::table('audit_logs')->insert([
                    'action' => 'provider.reclassified',
                    'subject_type' => 'App\\Models\\SmtpProvider',
                    'subject_id' => $provider->id,
                    'properties' => json_encode(['name' => $provider->name, 'from' => $provider->type, 'to' => $type, 'reason' => 'Mailtrap host']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Earlier code knows only Brevo and generic SMTP, so Mailtrap providers become generic SMTP again.
     */
    public function down(): void
    {
        DB::table('smtp_providers')->whereIn('type', ['mailtrap', 'mailtrap_sandbox'])->update(['type' => 'smtp']);
    }
};
