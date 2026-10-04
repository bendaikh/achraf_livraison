<?php

namespace App\Console\Commands;

use App\Models\SiftSetting;
use App\Models\SiftShipment;
use App\Services\Sift\SiftException;
use App\Services\Sift\SiftShipmentService;
use Illuminate\Console\Command;

/**
 * Polling fallback next to the Sift.ma webhooks: GET /parcels/{id} for every open parcel of every
 * company with the integration enabled and auto-sync on. Scheduled in-process
 * (routes/console.php, Schedule::call) because proc_open is disabled on the production hosting.
 */
class SiftSyncCommand extends Command
{
    protected $signature = 'sift:sync {--company= : Limiter à une société (id)} {--days=45 : Ignorer les colis plus anciens} {--limit=300 : Colis maximum par société}';

    protected $description = 'Synchronise les statuts Sift.ma des colis en cours (secours des webhooks)';

    public function handle(): int
    {
        $query = SiftSetting::query()->where('enabled', true)->where('auto_sync', true);
        if ($this->option('company')) {
            $query->where('company_id', (int) $this->option('company'));
        }
        foreach ($query->get() as $settings) {
            if (! $settings->hasCredentials()) {
                continue;
            }
            $shipments = SiftShipment::query()->trackable()->where('company_id', $settings->company_id)
                ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
                ->orderBy('last_synced_at')->limit(max(1, (int) $this->option('limit')))->with('order')->get();
            if ($shipments->isEmpty()) {
                continue;
            }
            try {
                $stats = SiftShipmentService::for($settings)->syncStatus($shipments);
            } catch (SiftException $e) {
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
