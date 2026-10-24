<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('prosend:check-domains')->dailyAt('03:00');
Schedule::command('prosend:evaluate-warmups')->dailyAt('00:05');
Schedule::command('prosend:prune')->dailyAt('02:30')->withoutOverlapping();
