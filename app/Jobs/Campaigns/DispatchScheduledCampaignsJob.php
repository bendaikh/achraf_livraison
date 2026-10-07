<?php

namespace App\Jobs\Campaigns;

use App\Services\Campaigns\CampaignLauncher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchScheduledCampaignsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('whatsapp-campaigns');
    }

    public function handle(CampaignLauncher $launcher): void
    {
        $launcher->launchDueScheduled();
    }
}
