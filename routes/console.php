<?php

use App\Services\SystemHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('prosend:check-domains')->dailyAt('03:00');
Schedule::command('prosend:evaluate-warmups')->dailyAt('00:05');
Schedule::command('prosend:prune')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('prosend:run-sequences')->everyMinute()->withoutOverlapping(10);
Schedule::command('prosend:sync-inboxes')->everyFiveMinutes()->withoutOverlapping(10);

// Shown on System health: proves the cron entry runs.
Schedule::call(fn () => Cache::put(SystemHealth::HEARTBEAT_KEY, now()->getTimestamp(), now()->addDay()))->everyMinute()->name('prosend:heartbeat');
