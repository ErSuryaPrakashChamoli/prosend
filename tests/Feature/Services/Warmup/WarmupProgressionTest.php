<?php

use App\Enums\BounceType;
use App\Enums\MessageStatus;
use App\Enums\WarmupRecommendation;
use App\Enums\WarmupStatus;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\SendingDomain;
use App\Models\SmtpProvider;
use App\Models\WarmupPlan;
use App\Services\Warmup\WarmupProgression;

/**
 * Accepted email through the provider, created the given number of days ago.
 */
function acceptedThrough(SmtpProvider $provider, int $count, int $daysAgo = 1, array $attributes = []): void
{
    EmailMessage::factory()
        ->for(SenderIdentity::factory()->for($provider))
        ->accepted()
        ->count($count)
        ->create(['smtp_provider_id' => $provider->id, 'created_at' => now()->subDays($daysAgo), 'accepted_at' => now()->subDays($daysAgo), ...$attributes]);
}

it('raises the daily limit when results are healthy and the limit was used', function () {
    $provider = SmtpProvider::factory()->create();
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 100)->create(['growth_percent' => 30, 'target_daily_volume' => 1000]);
    acceptedThrough($provider, 100);

    $assessment = app(WarmupProgression::class)->evaluate($plan);

    expect($assessment->recommendation)->toBe(WarmupRecommendation::Continue);
    expect($plan->refresh())
        ->current_daily_volume->toBe(130)
        ->status->toBe(WarmupStatus::Active);
});

it('holds the limit when the current limit was not actually used', function () {
    $provider = SmtpProvider::factory()->create();
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 200)->create();
    acceptedThrough($provider, 60);

    $assessment = app(WarmupProgression::class)->evaluate($plan);

    expect($assessment->recommendation)->toBe(WarmupRecommendation::HoldVolume);
    expect($plan->refresh()->current_daily_volume)->toBe(200);
});

it('pauses the plan when hard bounces exceed its limit', function () {
    $provider = SmtpProvider::factory()->create();
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 100)->create(['max_hard_bounce_rate' => 2]);
    acceptedThrough($provider, 90);
    acceptedThrough($provider, 10, attributes: ['status' => MessageStatus::Bounced, 'bounce_type' => BounceType::Hard]);

    $assessment = app(WarmupProgression::class)->evaluate($plan);

    expect($assessment->recommendation)->toBe(WarmupRecommendation::InvestigateBounces);
    expect($plan->refresh())
        ->status->toBe(WarmupStatus::Paused)
        ->current_daily_volume->toBe(100)
        ->paused_reason->toBe('Hard bounce rate 10% is above the plan limit of 2%.');
});

it('waits for enough data before judging', function () {
    $provider = SmtpProvider::factory()->create();
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 20)->create();
    acceptedThrough($provider, 20);

    expect(app(WarmupProgression::class)->evaluate($plan)->recommendation)->toBe(WarmupRecommendation::NotEnoughData);
    expect($plan->refresh()->current_daily_volume)->toBe(20);
});

it('completes the plan instead of exceeding the provider limit', function () {
    $provider = SmtpProvider::factory()->create(['daily_limit' => 120]);
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 100)->create(['target_daily_volume' => 120]);
    acceptedThrough($provider, 100);

    app(WarmupProgression::class)->evaluate($plan);

    expect($plan->refresh())
        ->current_daily_volume->toBe(120)
        ->status->toBe(WarmupStatus::Completed);
});

it('does not raise the limit again before the ramp interval passes', function () {
    $provider = SmtpProvider::factory()->create();
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 100)->create(['ramp_interval_days' => 3, 'last_increased_at' => now()->subDay()]);
    acceptedThrough($provider, 100);

    app(WarmupProgression::class)->evaluate($plan);

    expect($plan->refresh()->current_daily_volume)->toBe(100);
});

it('judges campaign email by the day it was accepted, not the day it was created', function () {
    $provider = SmtpProvider::factory()->create();
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 100)->create();
    EmailMessage::factory()
        ->for(SenderIdentity::factory()->for($provider))
        ->accepted()
        ->count(100)
        ->create(['smtp_provider_id' => $provider->id, 'created_at' => now()->subDays(10), 'accepted_at' => now()->subDay()]);

    $assessment = app(WarmupProgression::class)->evaluate($plan);

    expect($assessment->accepted)->toBe(100)
        ->and($assessment->recommendation)->toBe(WarmupRecommendation::Continue);
});

it('pauses on a bounce spike even when the sample is small or the domain is not authenticated', function () {
    $provider = SmtpProvider::factory()->create();
    SendingDomain::factory()->for($provider)->create();
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 50)->create(['max_hard_bounce_rate' => 2]);
    acceptedThrough($provider, 10);
    acceptedThrough($provider, 30, attributes: ['status' => MessageStatus::Bounced, 'bounce_type' => BounceType::Hard]);

    expect(app(WarmupProgression::class)->evaluate($plan)->recommendation)->toBe(WarmupRecommendation::InvestigateBounces);
    expect($plan->refresh()->status)->toBe(WarmupStatus::Paused);
});

it('does not pause on a single bounce in a tiny sample', function () {
    $provider = SmtpProvider::factory()->create();
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 50)->create();
    acceptedThrough($provider, 4);
    acceptedThrough($provider, 1, attributes: ['status' => MessageStatus::Bounced, 'bounce_type' => BounceType::Hard]);

    app(WarmupProgression::class)->evaluate($plan);

    expect($plan->refresh()->status)->toBe(WarmupStatus::Active);
});

it('pauses a completed plan when complaints spike', function () {
    $provider = SmtpProvider::factory()->create();
    $plan = WarmupPlan::factory()->for($provider)->active(currentDailyVolume: 1000)->create(['status' => WarmupStatus::Completed, 'max_complaint_rate' => 0.1]);
    acceptedThrough($provider, 100);
    acceptedThrough($provider, 3, attributes: ['status' => MessageStatus::Complained]);

    app(WarmupProgression::class)->evaluate($plan);

    expect($plan->refresh()->status)->toBe(WarmupStatus::Paused);
});
