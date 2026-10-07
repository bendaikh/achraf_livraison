<?php

namespace App\Services\Campaigns;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use Illuminate\Support\Facades\DB;

class CampaignStatsService
{
    public function refresh(WhatsAppCampaign $campaign): WhatsAppCampaign
    {
        $counts = $campaign->recipients()
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $sentLike = ['sent', 'delivered', 'read'];
        $sent = 0;
        foreach ($sentLike as $s) {
            $sent += (int) ($counts[$s] ?? 0);
        }

        $campaign->forceFill([
            'recipients_count' => (int) $campaign->recipients()
                ->whereNotIn('status', [WhatsAppCampaignRecipient::STATUS_EXCLUDED])
                ->count(),
            'excluded_count' => (int) ($counts[WhatsAppCampaignRecipient::STATUS_EXCLUDED] ?? 0),
            'sent_count' => $sent,
            'delivered_count' => (int) ($counts[WhatsAppCampaignRecipient::STATUS_DELIVERED] ?? 0)
                + (int) ($counts[WhatsAppCampaignRecipient::STATUS_READ] ?? 0),
            'read_count' => (int) ($counts[WhatsAppCampaignRecipient::STATUS_READ] ?? 0),
            'failed_count' => (int) ($counts[WhatsAppCampaignRecipient::STATUS_FAILED] ?? 0),
            'pending_count' => (int) ($counts[WhatsAppCampaignRecipient::STATUS_PENDING] ?? 0)
                + (int) ($counts[WhatsAppCampaignRecipient::STATUS_QUEUED] ?? 0),
            'stats' => [
                'by_status' => $counts->map(fn ($c) => (int) $c)->all(),
                'refreshed_at' => now()->toIso8601String(),
            ],
        ])->save();

        return $campaign->fresh();
    }

    public function companyCounters(int $companyId): array
    {
        $base = WhatsAppCampaign::query()->forCompany($companyId)->whereNull('archived_at');

        return [
            'total' => (clone $base)->count(),
            'draft' => (clone $base)->where('status', WhatsAppCampaign::STATUS_DRAFT)->count(),
            'scheduled' => (clone $base)->where('status', WhatsAppCampaign::STATUS_SCHEDULED)->count(),
            'running' => (clone $base)->where('status', WhatsAppCampaign::STATUS_RUNNING)->count(),
            'completed' => (clone $base)->where('status', WhatsAppCampaign::STATUS_COMPLETED)->count(),
            'paused' => (clone $base)->where('status', WhatsAppCampaign::STATUS_PAUSED)->count(),
            'error' => (clone $base)->where('status', WhatsAppCampaign::STATUS_ERROR)->count(),
        ];
    }
}
