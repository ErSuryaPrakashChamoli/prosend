<?php

use App\Enums\MessageStatus;
use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Storage;

it('removes bodies after 90 days and whole records after 13 months', function () {
    Storage::fake('local');
    Storage::disk('local')->put('email-attachments/old.pdf', '%PDF');
    $this->travelTo('2026-01-01 10:00:00');
    $ancient = EmailMessage::factory()->accepted()->create();
    $this->travelTo('2026-06-01 10:00:00');
    $old = EmailMessage::factory()->accepted()->create(['attachments' => [['path' => 'email-attachments/old.pdf', 'name' => 'old.pdf']], 'variables' => ['action_url' => 'https://x.test']]);
    $waiting = EmailMessage::factory()->status(MessageStatus::Queued)->create();
    $this->travelTo('2027-02-15 10:00:00');
    $recent = EmailMessage::factory()->accepted()->create();

    $this->artisan('prosend:prune')->assertSuccessful();

    expect(EmailMessage::query()->whereKey($ancient->id)->exists())->toBeFalse();
    expect($old->refresh())
        ->html_body->toBeNull()
        ->attachments->toBeNull()
        ->variables->toBeNull()
        ->status->toBe(MessageStatus::Accepted);
    expect($waiting->refresh()->html_body)->not->toBeNull();
    expect($recent->refresh()->html_body)->not->toBeNull();
    Storage::disk('local')->assertMissing('email-attachments/old.pdf');
});

it('removes API request logs after 30 days and audit entries after 24 months', function () {
    ApiRequestLog::query()->create(['request_id' => 'req_old', 'method' => 'GET', 'path' => 'api/v1/me', 'status' => 200, 'duration_ms' => 3, 'created_at' => now()->subDays(31)]);
    ApiRequestLog::query()->create(['request_id' => 'req_new', 'method' => 'GET', 'path' => 'api/v1/me', 'status' => 200, 'duration_ms' => 3, 'created_at' => now()->subDays(29)]);
    AuditLog::query()->create(['action' => 'old.thing'])->forceFill(['created_at' => now()->subMonths(25)])->save();
    AuditLog::query()->create(['action' => 'new.thing']);

    $this->artisan('prosend:prune')->assertSuccessful();

    expect(ApiRequestLog::query()->pluck('request_id')->all())->toBe(['req_new']);
    expect(AuditLog::query()->pluck('action')->all())->toBe(['new.thing']);
});
