<?php

namespace App\Console\Commands;

use App\Models\OzonSetting;
use App\Models\OzonShipment;
use App\Services\Ozon\OzonException;
use App\Services\Ozon\OzonShipmentService;
use Illuminate\Console\Command;

/**
 * Bulk Ozon Express tracking for every open parcel of every company with the integration
 * enabled and auto-sync on. Scheduled in-process (routes/console.php, Schedule::call) because
 * proc_open is disabled on the production hosting.
 */
class OzonSyncCommand extends Command
{
    protected $signature = 'ozon:sync {--company= : Limiter à une société (id)} {--days=60 : Ignorer les colis plus anciens}';

    protected $description = 'Synchronise les statuts Ozon Express des colis en cours';

    public function handle(): int
    {
        $query = OzonSetting::query()->where('enabled', true)->where('auto_sync', true);
        if ($this->option('company')) {
            $query->where('company_id', (int) $this->option('company'));
        }
        foreach ($query->get() as $settings) {
            if (! $settings->hasCredentials()) {
                continue;
            }
            $shipments = OzonShipment::query()->trackable()->where('company_id', $settings->company_id)
                ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
                ->with('order')->get();
            if ($shipments->isEmpty()) {
                continue;
            }
            try {
                $stats = OzonShipmentService::for($settings)->syncStatus($shipments);
            } catch (OzonException $e) {
                $this->error("Société #{$settings->company_id} : ".$e->getMessage());

                continue;
            }
            $this->info("Société #{$settings->company_id} : {$stats['checked']} vérifié(s), {$stats['updated']} mis à jour.");
            foreach (array_slice(array_unique($stats['errors']), 0, 5) as $error) {
                $this->error($error);
            }
        }

        return self::SUCCESS;
    }
}
