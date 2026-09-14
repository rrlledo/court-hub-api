<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:expire')->everyMinute();
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('memberships:expire')->dailyAt('00:05');
Schedule::command('notifications:prune')->dailyAt('01:00');
