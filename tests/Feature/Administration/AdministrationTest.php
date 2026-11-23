<?php

use App\Enums\DnsCheckStatus;
use App\Enums\EmailPurpose;
use App\Enums\ProviderHealth;
use App\Enums\SystemRole;
use App\Filament\Pages\SystemHealth as SystemHealthPage;
use App\Filament\Pages\Tenancy\EditClientSettings;
use App\Filament\Resources\FailedJobs\Pages\ManageFailedJobs;
use App\Jobs\SendEmailMessage;
use App\Models\Client;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\SendingDomain;
use App\Models\SmtpProvider;
use App\Models\User;
use App\Services\Deliverability\DeliverabilityScore;
use App\Services\SystemHealth;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeMailProviderDriver;

it('lets a client admin set the footer address and campaign defaults', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());

    Livewire::test(EditClientSettings::class)
        ->fillForm(['postal_address' => '12 MG Road, Bengaluru 560001', 'default_send_days' => [1, 2, 3], 'default_send_window_start' => '10:00', 'default_send_window_end' => '16:00', 'default_track_clicks' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Filament::getTenant()->refresh())
        ->postal_address->toBe('12 MG Road, Bengaluru 560001')
        ->default_send_days->toBe([1, 2, 3])
        ->default_track_clicks->toBeTrue();
});

it('keeps client settings from people without the settings permission', function () {
    $this->actingAs(User::factory()->sender()->create());

    expect(EditClientSettings::canView(Filament::getTenant()))->toBeFalse();
});

it('puts each client\'s own postal address in its marketing footer', function () {
    config(['prosend.marketing.postal_address' => 'Agency HQ']);
    $driver = FakeMailProviderDriver::install();
    Client::query()->where('slug', 'default')->update(['postal_address' => 'Client office, Pune']);
    $contact = Contact::factory()->consented()->create();
    $message = EmailMessage::factory()->for($contact)->create(['purpose' => EmailPurpose::Marketing, 'to_email' => $contact->email, 'html_body' => '<p>Hi</p>']);

    app()->call([new SendEmailMessage($message), 'handle']);

    expect($driver->sent[0]->getOriginalMessage()->getHtmlBody())->toContain('Client office, Pune')->not->toContain('Agency HQ');
});

it('reports a missing scheduler, a waiting queue and failing providers', function () {
    Cache::forget(SystemHealth::HEARTBEAT_KEY);
    SmtpProvider::factory()->create(['name' => 'Broken relay', 'health_status' => ProviderHealth::Failing]);

    $checks = collect(app(SystemHealth::class)->checks())->keyBy('name');

    expect($checks['Scheduler']['status'])->toBe('danger')
        ->and($checks['Mailbox providers']['detail'])->toContain('Broken relay');

    Cache::put(SystemHealth::HEARTBEAT_KEY, now()->getTimestamp());

    expect(collect(app(SystemHealth::class)->checks())->keyBy('name')['Scheduler']['status'])->toBe('ok');
});

it('shows system health and failed jobs to Super Admins only', function () {
    DB::table('failed_jobs')->insert(['uuid' => 'f-1', 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\DeliverWebhook']), 'exception' => "RuntimeException: Boom\n#0 trace", 'failed_at' => now()]);

    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());
    Livewire::test(SystemHealthPage::class)->assertForbidden();
    Livewire::test(ManageFailedJobs::class)->assertForbidden();

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(SystemHealthPage::class)->assertSee('Scheduler');
    Livewire::test(ManageFailedJobs::class)->assertSee('DeliverWebhook')->assertSee('RuntimeException: Boom')->assertDontSee('#0 trace');
});

it('scores a well set up sender as good and explains what is missing', function () {
    $identity = SenderIdentity::factory()->create(['email' => 'news@acme.test']);
    $identity->smtpProvider->forceFill(['health_status' => ProviderHealth::Healthy])->save();
    $domain = SendingDomain::factory()->create(['domain' => 'acme.test']);
    $domain->forceFill(['spf_status' => DnsCheckStatus::Found, 'dkim_status' => DnsCheckStatus::Found, 'dmarc_status' => DnsCheckStatus::Missing])->save();

    $result = app(DeliverabilityScore::class)->for($identity->refresh());

    expect($result['score'])->toBeLessThan(100)
        ->and($result['reasons'])->toContain('DMARC is missing.');
});
