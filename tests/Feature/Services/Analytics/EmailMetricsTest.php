<?php

use App\Enums\BounceType;
use App\Enums\EmailEventType;
use App\Enums\EmailPurpose;
use App\Enums\MessageStatus;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Services\Analytics\EmailMetrics;

it('separates submitted, accepted, delivered, and failed email in the period', function () {
    $this->travelTo('2026-10-20 12:00:00');
    EmailMessage::factory()->status(MessageStatus::Queued)->create();
    EmailMessage::factory()->accepted()->create();
    EmailMessage::factory()->status(MessageStatus::Delivered)->create(['delivered_at' => now()]);
    $bounced = EmailMessage::factory()->status(MessageStatus::Bounced)->create(['bounce_type' => BounceType::Hard]);
    EmailMessage::factory()->status(MessageStatus::Failed)->create();
    EmailMessage::factory()->status(MessageStatus::Unknown)->create();
    EmailMessage::factory()->status(MessageStatus::Suppressed)->create();
    EmailEvent::factory()->for($bounced)->create(['type' => EmailEventType::Opened]);
    $this->travelTo('2026-09-01 12:00:00');
    EmailMessage::factory()->accepted()->create();
    $this->travelTo('2026-10-24 12:00:00');

    $summary = app(EmailMetrics::class)->summary(['start_date' => '2026-10-01', 'end_date' => '2026-10-24']);

    expect($summary)->toBe([
        'submitted' => 7,
        'accepted' => 3,
        'delivered' => 1,
        'hard_bounces' => 1,
        'soft_bounces' => 0,
        'complaints' => 0,
        'failed' => 1,
        'unknown' => 1,
        'suppressed' => 1,
        'unsubscribes' => 0,
        'opened' => 1,
        'clicked' => 0,
    ]);
});

it('filters by email type', function () {
    EmailMessage::factory()->accepted()->create(['purpose' => EmailPurpose::Marketing]);
    EmailMessage::factory()->accepted()->create(['purpose' => EmailPurpose::Transactional]);

    $summary = app(EmailMetrics::class)->summary(['purpose' => EmailPurpose::Marketing->value]);

    expect($summary['submitted'])->toBe(1);
});

it('reports every day in the period, including days without email', function () {
    $this->travelTo('2026-10-22 09:00:00');
    EmailMessage::factory()->accepted()->create();
    $this->travelTo('2026-10-24 09:00:00');

    $days = app(EmailMetrics::class)->daily(['start_date' => '2026-10-21', 'end_date' => '2026-10-23']);

    expect($days)->toBe([
        '2026-10-21' => ['submitted' => 0, 'accepted' => 0, 'delivered' => 0, 'problems' => 0, 'hard_bounces' => 0, 'complaints' => 0],
        '2026-10-22' => ['submitted' => 1, 'accepted' => 1, 'delivered' => 0, 'problems' => 0, 'hard_bounces' => 0, 'complaints' => 0],
        '2026-10-23' => ['submitted' => 0, 'accepted' => 0, 'delivered' => 0, 'problems' => 0, 'hard_bounces' => 0, 'complaints' => 0],
    ]);
});

it('totals accepted email by sending domain', function () {
    $acme = SenderIdentity::factory()->create(['email' => 'news@acme.test']);
    $acmeSupport = SenderIdentity::factory()->create(['email' => 'help@acme.test']);
    $other = SenderIdentity::factory()->create(['email' => 'hi@other.test']);
    EmailMessage::factory()->for($acme)->accepted()->count(2)->create();
    EmailMessage::factory()->for($acmeSupport)->accepted()->create();
    EmailMessage::factory()->for($other)->accepted()->create();
    EmailMessage::factory()->for($other)->status(MessageStatus::Failed)->create();

    expect(app(EmailMetrics::class)->acceptedByDomain([]))->toBe(['acme.test' => 3, 'other.test' => 1]);
});
