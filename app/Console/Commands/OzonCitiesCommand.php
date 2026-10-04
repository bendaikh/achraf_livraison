<?php

namespace App\Console\Commands;

use App\Models\OzonSetting;
use App\Services\Ozon\OzonCityService;
use App\Services\Ozon\OzonException;
use Illuminate\Console\Command;

/** Imports the official Ozon Express city list (public GET /cities) and auto-matches order cities. */
class OzonCitiesCommand extends Command
{
    protected $signature = 'ozon:cities';

    protected $description = 'Synchronise la liste officielle des villes Ozon Express';

    public function handle(OzonCityService $cities): int
    {
        try {
            $res = $cities->sync();
        } catch (OzonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$res['count']} villes Ozon synchronisées.");
        foreach (OzonSetting::query()->pluck('company_id') as $companyId) {
            $stats = $cities->autoMatchOrderCities((int) $companyId);
            $this->info("Société #{$companyId} : {$stats['matched']} ville(s) associée(s), {$stats['unmatched']} à associer.");
        }

        return self::SUCCESS;
    }
}
