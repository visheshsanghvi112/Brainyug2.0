<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily proactive expiry scan for near-expiry inventory.
Schedule::command('stock:check-expiry --days=30')
    ->dailyAt('08:00')
    ->withoutOverlapping();
