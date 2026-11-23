<?php

use App\Services\Campaigns\SendingWindow;
use Illuminate\Support\Carbon;

it('is open only on the chosen days and hours, in its own time zone', function () {
    $window = new SendingWindow([1, 2, 3, 4, 5], '09:00', '17:00', 'Asia/Kolkata');

    expect($window->contains(Carbon::parse('2026-11-23 03:30', 'UTC')))->toBeTrue()  // Mon 09:00 Kolkata
        ->and($window->contains(Carbon::parse('2026-11-23 03:29', 'UTC')))->toBeFalse()
        ->and($window->contains(Carbon::parse('2026-11-23 11:30', 'UTC')))->toBeFalse() // Mon 17:00 Kolkata, end excluded
        ->and($window->contains(Carbon::parse('2026-11-28 05:00', 'UTC')))->toBeFalse(); // Saturday
});

it('finds the next opening after a weekend or after hours', function () {
    $window = new SendingWindow([1, 2, 3, 4, 5], '09:00', '17:00', 'UTC');

    expect($window->nextOpening(Carbon::parse('2026-11-28 12:00', 'UTC'))->toDateTimeString())->toBe('2026-11-30 09:00:00')
        ->and($window->nextOpening(Carbon::parse('2026-11-24 18:00', 'UTC'))->toDateTimeString())->toBe('2026-11-25 09:00:00')
        ->and($window->nextOpening(Carbon::parse('2026-11-24 07:00', 'UTC'))->toDateTimeString())->toBe('2026-11-24 09:00:00')
        ->and($window->nextOpening(Carbon::parse('2026-11-24 10:15', 'UTC'))->toDateTimeString())->toBe('2026-11-24 10:15:00');
});

it('treats missing days or hours as no restriction', function () {
    expect((new SendingWindow(null, null, null, 'UTC'))->isUnrestricted())->toBeTrue()
        ->and((new SendingWindow([1, 2, 3, 4, 5, 6, 7], null, null, 'UTC'))->isUnrestricted())->toBeTrue()
        ->and((new SendingWindow(null, '09:00', '17:00', 'UTC'))->describe())->toBe('Every day, 09:00–17:00 (UTC)');
});
