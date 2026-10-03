<?php

use App\Services\Shopify\CatalogSyncService;
use App\Services\Team\CommissionService;
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

/*
| Shopify product catalog (T4): incremental every hour, full pass once a day (inside syncAll).
| Webhooks products/* + inventory_levels/update keep it live in between.
*/
Schedule::call(fn () => app(CatalogSyncService::class)->syncAll())
    ->name('shopify-catalog-sync')
    ->hourly()
    ->withoutOverlapping(55);

/*
| Agent commissions (T6): fixed monthly amounts of the previous month, on the 1st.
*/
Schedule::call(fn () => app(CommissionService::class)->generateMonthly())
    ->name('agent-monthly-commissions')
    ->monthlyOn(1, '00:30')
    ->withoutOverlapping(30);
