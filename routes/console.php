<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Speedaf tracking sync. Schedule::call (in-process Artisan::call) instead of
| Schedule::command, which would spawn a sub-process through proc_open (disabled on Hostinger).
| Cron on the server: * * * * * php /path/to/artisan schedule:run
*/
Schedule::call(fn () => Artisan::call('speedaf:sync'))
    ->name('speedaf-sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping(25);
