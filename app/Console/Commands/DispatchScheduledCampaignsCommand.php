<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignLauncher;
use Illuminate\Console\Command;

class DispatchScheduledCampaignsCommand extends Command
{
    protected $signature = 'campaigns:dispatch-scheduled';

    protected $description = 'Lance les campagnes WhatsApp dont la date/heure programmée est atteinte';

    public function handle(CampaignLauncher $launcher): int
    {
        $n = $launcher->launchDueScheduled();
        $this->info("Campagnes lancées: {$n}");

        return self::SUCCESS;
    }
}
