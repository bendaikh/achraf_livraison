<?php

namespace App\Services\Campaigns;

use App\Jobs\Campaigns\ProcessCampaignBatchJob;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignExclusionReason;
use App\Models\WhatsAppCampaignRecipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Snapshots audience into campaign_recipients and queues batch send jobs.
 */
class CampaignLauncher
{
    public function __construct(
        protected AudienceQueryBuilder $audience,
        protected CampaignStatsService $stats,
    ) {}

    public function schedule(WhatsAppCampaign $campaign, Carbon $at, ?User $user = null): WhatsAppCampaign
    {
        abort_unless($campaign->canSend(), 422, 'Cette campagne ne peut pas être programmée.');
        abort_unless($campaign->whatsapp_account_id && $campaign->whatsapp_template_id, 422, 'Compte et template requis.');

        $campaign->forceFill([
            'status' => WhatsAppCampaign::STATUS_SCHEDULED,
            'scheduled_at' => $at,
            'updated_by' => $user?->id,
            'error_message' => null,
        ])->save();

        return $campaign->fresh(['account', 'template', 'creator']);
    }

    public function launch(WhatsAppCampaign $campaign, ?User $user = null, bool $excludeRecentlyContacted = false): WhatsAppCampaign
    {
        abort_unless(
            in_array($campaign->status, [
                WhatsAppCampaign::STATUS_DRAFT,
                WhatsAppCampaign::STATUS_SCHEDULED,
                WhatsAppCampaign::STATUS_PAUSED,
            ], true) || ($campaign->status === WhatsAppCampaign::STATUS_RUNNING && $campaign->recipients()->count() === 0),
            422,
            'Cette campagne ne peut pas être lancée.'
        );
        abort_unless($campaign->whatsapp_account_id && $campaign->whatsapp_template_id, 422, 'Compte et template requis.');

        /** @var Company $company */
        $company = $campaign->company;

        $resolved = $this->audience->resolveWithExclusions(
            $company,
            $campaign->audience_definition,
            $campaign->manual_phone_keys ?? [],
            $campaign->exclusion_phone_keys ?? [],
            $excludeRecentlyContacted
        );

        abort_if($resolved['included_count'] === 0, 422, 'Aucun destinataire éligible après exclusions.');

        DB::transaction(function () use ($campaign, $resolved, $user) {
            // Fresh snapshot: clear previous pending snapshot if relaunching from draft/scheduled
            if (in_array($campaign->status, [WhatsAppCampaign::STATUS_DRAFT, WhatsAppCampaign::STATUS_SCHEDULED], true)) {
                $campaign->recipients()->delete();
                $campaign->exclusionReasons()->delete();
            }

            foreach ($resolved['details'] as $detail) {
                $row = $detail['row'];
                $exists = WhatsAppCampaignRecipient::query()
                    ->where('whatsapp_campaign_id', $campaign->id)
                    ->where('phone_key', $detail['phone_key'])
                    ->exists();
                if ($exists) {
                    continue;
                }

                WhatsAppCampaignRecipient::query()->create([
                    'company_id' => $campaign->company_id,
                    'whatsapp_campaign_id' => $campaign->id,
                    'phone_key' => $detail['phone_key'],
                    'customer_name' => $row->customer_name ?? null,
                    'phone' => $row->phone ?? $detail['phone_key'],
                    'status' => $detail['excluded']
                        ? WhatsAppCampaignRecipient::STATUS_EXCLUDED
                        : WhatsAppCampaignRecipient::STATUS_PENDING,
                    'exclude_reason' => $detail['reason'],
                ]);
            }

            foreach ($resolved['excluded'] as $reason => $count) {
                WhatsAppCampaignExclusionReason::query()->updateOrCreate(
                    [
                        'whatsapp_campaign_id' => $campaign->id,
                        'reason' => $reason,
                    ],
                    [
                        'company_id' => $campaign->company_id,
                        'count' => $count,
                    ]
                );
            }

            $pending = $campaign->recipients()
                ->where('status', WhatsAppCampaignRecipient::STATUS_PENDING)
                ->count();

            $campaign->forceFill([
                'status' => WhatsAppCampaign::STATUS_RUNNING,
                'started_at' => $campaign->started_at ?? now(),
                'paused_at' => null,
                'finished_at' => null,
                'error_message' => null,
                'recipients_count' => $pending,
                'excluded_count' => $resolved['excluded_count'],
                'pending_count' => $pending,
                'updated_by' => $user?->id,
            ])->save();
        });

        $this->stats->refresh($campaign->fresh());
        $this->dispatchBatches($campaign->fresh());

        return $campaign->fresh(['account', 'template', 'creator', 'exclusionReasons']);
    }

    public function dispatchBatches(WhatsAppCampaign $campaign): void
    {
        $company = $campaign->company;
        $batchSize = (int) ($company?->campaignSetting('batch_size', 25) ?: 25);
        $batchSize = max(1, min(100, $batchSize));

        $ids = $campaign->recipients()
            ->where('status', WhatsAppCampaignRecipient::STATUS_PENDING)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach (array_chunk($ids, $batchSize) as $chunk) {
            ProcessCampaignBatchJob::dispatch($campaign->id, $chunk)
                ->onQueue('whatsapp-campaigns');
        }

        if ($ids === []) {
            $this->stats->refresh($campaign);
            $this->maybeComplete($campaign->fresh());
        }
    }

    public function pause(WhatsAppCampaign $campaign, ?User $user = null): WhatsAppCampaign
    {
        abort_unless($campaign->status === WhatsAppCampaign::STATUS_RUNNING, 422, 'Seule une campagne en cours peut être suspendue.');
        $campaign->forceFill([
            'status' => WhatsAppCampaign::STATUS_PAUSED,
            'paused_at' => now(),
            'updated_by' => $user?->id,
        ])->save();

        return $campaign->fresh();
    }

    public function resume(WhatsAppCampaign $campaign, ?User $user = null): WhatsAppCampaign
    {
        abort_unless($campaign->status === WhatsAppCampaign::STATUS_PAUSED, 422, 'Seule une campagne suspendue peut être reprise.');
        $campaign->forceFill([
            'status' => WhatsAppCampaign::STATUS_RUNNING,
            'paused_at' => null,
            'updated_by' => $user?->id,
        ])->save();

        // Re-queue failed retriable + still pending
        $campaign->recipients()
            ->where('status', WhatsAppCampaignRecipient::STATUS_FAILED)
            ->where('retriable', true)
            ->update([
                'status' => WhatsAppCampaignRecipient::STATUS_PENDING,
                'error_code' => null,
                'error_message' => null,
            ]);

        $this->dispatchBatches($campaign->fresh());

        return $campaign->fresh();
    }

    public function maybeComplete(WhatsAppCampaign $campaign): void
    {
        if (! in_array($campaign->status, [WhatsAppCampaign::STATUS_RUNNING, WhatsAppCampaign::STATUS_PAUSED], true)) {
            return;
        }

        $pending = $campaign->recipients()
            ->whereIn('status', [
                WhatsAppCampaignRecipient::STATUS_PENDING,
                WhatsAppCampaignRecipient::STATUS_QUEUED,
            ])
            ->exists();

        if ($pending) {
            return;
        }

        if ($campaign->status === WhatsAppCampaign::STATUS_PAUSED) {
            return;
        }

        $campaign->forceFill([
            'status' => WhatsAppCampaign::STATUS_COMPLETED,
            'finished_at' => now(),
        ])->save();
        $this->stats->refresh($campaign);
    }

    public function launchDueScheduled(): int
    {
        $count = 0;
        WhatsAppCampaign::query()
            ->where('status', WhatsAppCampaign::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->each(function (WhatsAppCampaign $campaign) use (&$count) {
                try {
                    $this->launch($campaign);
                    $count++;
                } catch (\Throwable $e) {
                    $campaign->forceFill([
                        'status' => WhatsAppCampaign::STATUS_ERROR,
                        'error_message' => mb_substr($e->getMessage(), 0, 2000),
                    ])->save();
                }
            });

        return $count;
    }
}
