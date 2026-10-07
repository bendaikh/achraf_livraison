<?php

namespace App\Jobs\Campaigns;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\CampaignStatsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCampaignBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $campaignId,
        /** @var list<int> */
        public array $recipientIds,
    ) {
        $this->onQueue('whatsapp-campaigns');
    }

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(CampaignSender $sender, CampaignStatsService $stats, CampaignLauncher $launcher): void
    {
        $campaign = WhatsAppCampaign::query()->find($this->campaignId);
        if (! $campaign) {
            return;
        }

        if ($campaign->status === WhatsAppCampaign::STATUS_PAUSED) {
            return;
        }

        if ($campaign->status !== WhatsAppCampaign::STATUS_RUNNING) {
            return;
        }

        $company = $campaign->company;
        $rate = (int) ($company?->campaignSetting('rate_limit_per_minute', 30) ?: 30);
        $delayMs = $rate > 0 ? (int) floor(60000 / max(1, $rate)) : 0;

        foreach ($this->recipientIds as $id) {
            $campaign->refresh();
            if ($campaign->status !== WhatsAppCampaign::STATUS_RUNNING) {
                break;
            }

            $recipient = WhatsAppCampaignRecipient::query()
                ->where('whatsapp_campaign_id', $campaign->id)
                ->whereKey($id)
                ->first();

            if (! $recipient || $recipient->status !== WhatsAppCampaignRecipient::STATUS_PENDING) {
                continue;
            }

            $recipient->forceFill([
                'status' => WhatsAppCampaignRecipient::STATUS_QUEUED,
                'queued_at' => now(),
            ])->save();

            try {
                $sender->sendRecipient($recipient);
            } catch (\Throwable $e) {
                Log::warning('Campaign batch recipient failed', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $stats->refresh($campaign->fresh());
        $launcher->maybeComplete($campaign->fresh());
    }
}
