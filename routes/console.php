<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tickets:promote-overdue')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tickets:retry-mail')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('tickets:auto-close-confirmations')->dailyAt('01:00')->timezone('Asia/Manila')->withoutOverlapping();
