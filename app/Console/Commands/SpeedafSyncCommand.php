<?php

namespace App\Console\Commands;

use App\Models\SpeedafSetting;
use App\Models\SpeedafShipment;
use App\Services\Speedaf\SpeedafShipmentService;
use Illuminate\Console\Command;

/**
 * Polls Speedaf tracking for every open waybill of every company with the integration enabled
 * and auto-sync on. Scheduled in-process (routes/console.php, Schedule::call) because
 * proc_open is disabled on the production hosting.
 */
class SpeedafSyncCommand extends Command
{
    protected $signature = 'speedaf:sync {--company= : Limiter à une société (id)} {--days=45 : Ignorer les envois plus anciens}';

    protected $description = 'Synchronise les statuts de suivi Speedaf des commandes envoyées';

    public function handle(): int
    {
        $query = SpeedafSetting::query()->where('enabled', true)->where('auto_sync', true);
        if ($this->option('company')) {
            $query->where('company_id', (int) $this->option('company'));
        }

        foreach ($query->get() as $settings) {
            if (! $settings->hasCredentials()) {
                continue;
            }
            $shipments = SpeedafShipment::query()->trackable()
                ->where('company_id', $settings->company_id)
                ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
                ->with('order')->get();
            if ($shipments->isEmpty()) {
                continue;
            }
            $stats = SpeedafShipmentService::for($settings)->sync($shipments);
            $this->info("Société #{$settings->company_id} : {$stats['checked']} vérifié(s), {$stats['updated']} mis à jour.");
            foreach (array_unique($stats['errors']) as $error) {
                $this->error($error);
            }
        }

        return self::SUCCESS;
    }
}
