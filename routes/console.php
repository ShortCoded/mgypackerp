<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('presence:mark-stale-offline')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('notifications:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('queue:work --queue=default --stop-when-empty --max-jobs=100 --max-time=50 --tries=3 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping();
