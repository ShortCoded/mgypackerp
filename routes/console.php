<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:work --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('presence:mark-stale-offline')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('notifications:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping();
