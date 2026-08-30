<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('enrollment:release-expired-reservations')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('enrollment:dispatch-window-notifications')
    ->dailyAt('08:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
