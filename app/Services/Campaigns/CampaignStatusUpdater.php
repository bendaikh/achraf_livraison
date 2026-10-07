<?php

namespace App\Services\Campaigns;

use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppMessage;

/**
 * Syncs campaign recipient delivery status from WhatsApp message webhook updates.
 */
class CampaignStatusUpdater
{
    public function __construct(protected CampaignStatsService $stats) {}

    public function syncFromMessage(WhatsAppMessage $message): void
    {
        if (! $message->wa_message_id) {
            return;
        }

        $recipient = WhatsAppCampaignRecipient::query()
            ->where('wa_message_id', $message->wa_message_id)
            ->first();

        if (! $recipient) {
            $recipient = WhatsAppCampaignRecipient::query()
                ->where('whatsapp_message_id', $message->id)
                ->first();
        }

        if (! $recipient) {
            return;
        }

        $mapped = match ($message->status) {
            WhatsAppMessage::STATUS_SENT => WhatsAppCampaignRecipient::STATUS_SENT,
            WhatsAppMessage::STATUS_DELIVERED => WhatsAppCampaignRecipient::STATUS_DELIVERED,
            WhatsAppMessage::STATUS_READ => WhatsAppCampaignRecipient::STATUS_READ,
            WhatsAppMessage::STATUS_FAILED => WhatsAppCampaignRecipient::STATUS_FAILED,
            default => null,
        };

        if (! $mapped) {
            return;
        }

        $rank = [
            WhatsAppCampaignRecipient::STATUS_PENDING => 0,
            WhatsAppCampaignRecipient::STATUS_QUEUED => 1,
            WhatsAppCampaignRecipient::STATUS_SENT => 2,
            WhatsAppCampaignRecipient::STATUS_DELIVERED => 3,
            WhatsAppCampaignRecipient::STATUS_READ => 4,
            WhatsAppCampaignRecipient::STATUS_FAILED => 5,
        ];

        $current = $rank[$recipient->status] ?? -1;
        $new = $rank[$mapped] ?? -1;
        if ($mapped !== WhatsAppCampaignRecipient::STATUS_FAILED && $new < $current) {
            return;
        }

        $fill = ['status' => $mapped];
        if ($mapped === WhatsAppCampaignRecipient::STATUS_SENT && ! $recipient->sent_at) {
            $fill['sent_at'] = $message->status_timestamp ?? now();
        }
        if ($mapped === WhatsAppCampaignRecipient::STATUS_DELIVERED) {
            $fill['delivered_at'] = $message->status_timestamp ?? now();
        }
        if ($mapped === WhatsAppCampaignRecipient::STATUS_READ) {
            $fill['read_at'] = $message->status_timestamp ?? now();
            if (! $recipient->delivered_at) {
                $fill['delivered_at'] = $message->status_timestamp ?? now();
            }
        }
        if ($mapped === WhatsAppCampaignRecipient::STATUS_FAILED) {
            $fill['failed_at'] = $message->status_timestamp ?? now();
            $fill['error_code'] = $message->error_code;
            $fill['error_message'] = $message->error_message;
            $fill['retriable'] = false;
        }

        $recipient->forceFill($fill)->save();

        if ($recipient->campaign) {
            $this->stats->refresh($recipient->campaign);
        }
    }
}
