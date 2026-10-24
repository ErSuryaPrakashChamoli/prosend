<?php

use App\Enums\MessageStatus;
use App\Enums\WarmupStatus;
use App\Jobs\SendEmailMessage;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\SendingDomain;
use App\Models\SmtpProvider;
use App\Models\WarmupPlan;
use App\Services\Warmup\WarmupGate;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMailProviderDriver;

/**
 * @return array{until: CarbonInterface, reason: string}|null
 */
function warmupDeferral(EmailMessage $message, SmtpProvider $provider): ?array
{
    $gate = app(WarmupGate::class);
    $plan = $gate->planFor($provider, $message->senderIdentity->email);

    return $plan ? $gate->deferral($plan) : null;
}

it('lets email through while the plan has room today', function () {
    $provider = SmtpProvider::factory()->create();
    WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 2)->create();
    $message = EmailMessage::factory()->for(SenderIdentity::factory()->for($provider))->create();

    expect(warmupDeferral($message, $provider))->toBeNull();
});

it('holds email until tomorrow once the daily warm-up limit is used', function () {
    $this->travelTo('2026-10-26 10:00:00');
    $provider = SmtpProvider::factory()->create();
    $sender = SenderIdentity::factory()->for($provider)->create();
    WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 1)->create(['name' => 'New domain']);
    EmailMessage::factory()->for($sender)->accepted()->create(['smtp_provider_id' => $provider->id]);
    $message = EmailMessage::factory()->for($sender)->create();

    $deferral = warmupDeferral($message, $provider);

    expect($deferral['until']->toDateTimeString())->toBe('2026-10-27 00:00:00')
        ->and($deferral['reason'])->toBe('The warm-up plan "New domain" allows 1 emails today.');
});

it('holds email outside the sending hours', function () {
    $this->travelTo('2026-10-26 07:30:00');
    $provider = SmtpProvider::factory()->create();
    WarmupPlan::factory()->for($provider)->active()->create(['sending_window_start' => '09:00', 'sending_window_end' => '17:00']);
    $message = EmailMessage::factory()->for(SenderIdentity::factory()->for($provider))->create();

    expect(warmupDeferral($message, $provider)['until']->toDateTimeString())->toBe('2026-10-26 09:00:00');
});

it('holds all email covered by a paused plan', function () {
    $provider = SmtpProvider::factory()->create();
    WarmupPlan::factory()->for($provider)->active()->create(['status' => WarmupStatus::Paused]);
    $message = EmailMessage::factory()->for(SenderIdentity::factory()->for($provider))->create();

    expect(warmupDeferral($message, $provider))->not->toBeNull();
});

it('only limits email from the plan domain', function () {
    $provider = SmtpProvider::factory()->create();
    WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 1)->create([
        'sending_domain_id' => SendingDomain::factory()->for($provider)->create(['domain' => 'new.test'])->id,
        'status' => WarmupStatus::Paused,
    ]);
    $message = EmailMessage::factory()->for(SenderIdentity::factory()->for($provider)->state(['email' => 'hi@established.test']))->create();

    expect(warmupDeferral($message, $provider))->toBeNull();
});

it('does not limit email for a plan awaiting approval', function () {
    $provider = SmtpProvider::factory()->create();
    WarmupPlan::factory()->for($provider)->create(['status' => WarmupStatus::Draft]);
    $message = EmailMessage::factory()->for(SenderIdentity::factory()->for($provider))->create();

    expect(warmupDeferral($message, $provider))->toBeNull();
});

it('makes the send job wait instead of sending past the warm-up limit', function () {
    $driver = FakeMailProviderDriver::install();
    $provider = SmtpProvider::factory()->create();
    $sender = SenderIdentity::factory()->for($provider)->create();
    WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 1)->create();
    EmailMessage::factory()->for($sender)->accepted()->create(['smtp_provider_id' => $provider->id]);
    $message = EmailMessage::factory()->for($sender)->create();
    Queue::fake([SendEmailMessage::class]);

    app()->call([new SendEmailMessage($message), 'handle']);

    expect($driver->sent)->toBeEmpty();
    expect($message->refresh()->status)->toBe(MessageStatus::Deferred);
    Queue::assertPushed(SendEmailMessage::class, 1);
});

it('keeps enforcing a completed plan', function () {
    $this->travelTo('2026-10-26 10:00:00');
    $provider = SmtpProvider::factory()->create();
    $sender = SenderIdentity::factory()->for($provider)->create();
    WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 1)->create(['status' => WarmupStatus::Completed, 'target_daily_volume' => 1]);
    EmailMessage::factory()->for($sender)->accepted()->create(['smtp_provider_id' => $provider->id]);

    expect(warmupDeferral(EmailMessage::factory()->for($sender)->create(), $provider))->not->toBeNull();
});

it('counts email being sent right now toward the daily limit', function () {
    $provider = SmtpProvider::factory()->create();
    $sender = SenderIdentity::factory()->for($provider)->create();
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 1)->create();
    EmailMessage::factory()->for($sender)->status(MessageStatus::Sending)->create(['sending_started_at' => now()]);

    expect(app(WarmupGate::class)->sentToday($plan))->toBe(1)
        ->and(warmupDeferral(EmailMessage::factory()->for($sender)->create(), $provider))->not->toBeNull();
});
