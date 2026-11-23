<?php

use App\Enums\ProviderType;
use App\Models\AuditLog;
use App\Models\Scopes\ClientScope;
use App\Models\SmtpProvider;
use Illuminate\Support\Facades\DB;

it('gives providers on Mailtrap hosts the type their host implies, and audits each change', function () {
    $migration = require database_path('migrations/2026_10_29_091944_reclassify_mailtrap_smtp_providers.php');
    $sandbox = SmtpProvider::factory()->create(['type' => ProviderType::Smtp, 'host' => 'sandbox.smtp.mailtrap.io']);
    $bulk = SmtpProvider::factory()->create(['type' => ProviderType::Smtp, 'host' => 'Bulk.SMTP.mailtrap.io.']);
    $brevo = SmtpProvider::factory()->create();
    $other = SmtpProvider::factory()->create(['type' => ProviderType::Smtp, 'host' => 'smtp.example.net']);

    $migration->up();

    expect($sandbox->refresh()->type)->toBe(ProviderType::MailtrapSandbox)
        ->and($bulk->refresh()->type)->toBe(ProviderType::Mailtrap)
        ->and($brevo->refresh()->type)->toBe(ProviderType::Brevo)
        ->and($other->refresh()->type)->toBe(ProviderType::Smtp);
    // This migration ran before clients existed and writes entries without a client; a later migration assigned them.
    $entries = AuditLog::query()->withoutGlobalScope(ClientScope::class)->where('action', 'provider.reclassified');
    expect((clone $entries)->where('subject_id', $sandbox->id)->sole()->properties)
        ->toMatchArray(['from' => 'smtp', 'to' => 'mailtrap_sandbox']);
    expect($entries->count())->toBe(2);
});

it('turns Mailtrap providers back into generic SMTP on rollback, so earlier code can still read them', function () {
    $migration = require database_path('migrations/2026_10_29_091944_reclassify_mailtrap_smtp_providers.php');
    $provider = SmtpProvider::factory()->mailtrapSandbox()->create();

    $migration->down();

    expect(DB::table('smtp_providers')->where('id', $provider->id)->value('type'))->toBe('smtp');
});
