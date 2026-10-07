<?php

namespace App\Services\Campaigns;

use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends a single campaign recipient message via WhatsApp Cloud API (or fake mode).
 * Idempotent: a recipient already sent/delivered/read is never resent.
 */
class CampaignSender
{
    public function __construct(
        protected WhatsAppCloudClient $client,
        protected CampaignVariableResolver $variables,
    ) {}

    public function shouldFake(WhatsAppCampaign $campaign): bool
    {
        if (app()->environment('testing')) {
            return true;
        }
        $company = $campaign->company;
        if ($company && (bool) $company->campaignSetting('fake_sender', false)) {
            return true;
        }

        return (bool) config('campaigns.fake_sender', false);
    }

    public function sendRecipient(WhatsAppCampaignRecipient $recipient, ?User $actor = null): WhatsAppCampaignRecipient
    {
        $recipient = $recipient->fresh();
        $campaign = $recipient->campaign()->with(['account', 'template', 'company'])->first();

        if (! $campaign || $campaign->status === WhatsAppCampaign::STATUS_PAUSED) {
            return $recipient;
        }

        if ($campaign->status !== WhatsAppCampaign::STATUS_RUNNING) {
            return $recipient;
        }

        // Idempotence: never send twice
        if (in_array($recipient->status, [
            WhatsAppCampaignRecipient::STATUS_SENT,
            WhatsAppCampaignRecipient::STATUS_DELIVERED,
            WhatsAppCampaignRecipient::STATUS_READ,
            WhatsAppCampaignRecipient::STATUS_EXCLUDED,
            WhatsAppCampaignRecipient::STATUS_SKIPPED,
        ], true)) {
            return $recipient;
        }

        if ($recipient->wa_message_id) {
            return $recipient;
        }

        $account = $campaign->account;
        $template = $campaign->template;
        if (! $account || ! $template) {
            $this->markFailed($recipient, 'missing_account_or_template', 'Compte ou template manquant.', false);

            return $recipient->fresh();
        }

        $resolved = $this->variables->resolve(
            $campaign->company,
            $recipient->phone_key,
            $template->body_text,
            $campaign->variable_mapping ?? []
        );

        $recipient->forceFill([
            'resolved_variables' => $resolved['variables'],
            'preview_body' => $resolved['preview'],
            'attempts' => $recipient->attempts + 1,
            'queued_at' => $recipient->queued_at ?? now(),
        ])->save();

        try {
            if ($this->shouldFake($campaign)) {
                return $this->sendFake($recipient, $account, $template, $resolved, $actor);
            }

            return $this->sendReal($recipient, $campaign, $account, $template, $resolved, $actor);
        } catch (\Throwable $e) {
            Log::warning('Campaign send failed', [
                'campaign_id' => $campaign->id,
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
            $retriable = $this->isRetriable($e);
            $this->markFailed($recipient, 'send_error', $e->getMessage(), $retriable);
            if ($retriable) {
                throw $e;
            }

            return $recipient->fresh();
        }
    }

    protected function sendFake(
        WhatsAppCampaignRecipient $recipient,
        WhatsAppAccount $account,
        WhatsAppTemplate $template,
        array $resolved,
        ?User $actor
    ): WhatsAppCampaignRecipient {
        $wamid = 'wamid.fake.'.uniqid('', true);

        return DB::transaction(function () use ($recipient, $account, $template, $resolved, $actor, $wamid) {
            $conversation = $this->ensureConversation($account, $recipient);
            $message = WhatsAppMessage::query()->create([
                'company_id' => $recipient->company_id,
                'whatsapp_conversation_id' => $conversation->id,
                'whatsapp_account_id' => $account->id,
                'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
                'type' => 'template',
                'body' => $resolved['preview'],
                'template_name' => $template->name,
                'template_language' => $template->language,
                'template_components' => $resolved['variables'],
                'status' => WhatsAppMessage::STATUS_SENT,
                'wa_message_id' => $wamid,
                'sent_by_user_id' => $actor?->id,
                'meta_timestamp' => now(),
                'raw_payload' => ['fake' => true, 'messages' => [['id' => $wamid]]],
            ]);

            $recipient->forceFill([
                'status' => WhatsAppCampaignRecipient::STATUS_SENT,
                'whatsapp_message_id' => $message->id,
                'wa_message_id' => $wamid,
                'sent_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'retriable' => false,
            ])->save();

            return $recipient->fresh();
        });
    }

    protected function sendReal(
        WhatsAppCampaignRecipient $recipient,
        WhatsAppCampaign $campaign,
        WhatsAppAccount $account,
        WhatsAppTemplate $template,
        array $resolved,
        ?User $actor
    ): WhatsAppCampaignRecipient {
        if (! $account->isConnected()) {
            throw new \RuntimeException('Le numéro WhatsApp n’est pas connecté.');
        }
        if (! $template->isApproved()) {
            throw new \RuntimeException('Ce template n’est pas encore approuvé par Meta.');
        }

        $components = [];
        if ($resolved['variables'] !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($value) => ['type' => 'text', 'text' => (string) ($value !== '' ? $value : '-')],
                    array_values($resolved['variables'])
                ),
            ];
        }

        $to = PhoneNormalizer::digits($recipient->phone ?: $recipient->phone_key);
        $conversation = $this->ensureConversation($account, $recipient);

        return DB::transaction(function () use ($recipient, $account, $template, $resolved, $actor, $components, $to, $conversation) {
            $message = WhatsAppMessage::query()->create([
                'company_id' => $recipient->company_id,
                'whatsapp_conversation_id' => $conversation->id,
                'whatsapp_account_id' => $account->id,
                'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
                'type' => 'template',
                'body' => $resolved['preview'],
                'template_name' => $template->name,
                'template_language' => $template->language,
                'template_components' => $resolved['variables'],
                'status' => WhatsAppMessage::STATUS_PENDING,
                'sent_by_user_id' => $actor?->id,
                'meta_timestamp' => now(),
            ]);

            $response = $this->client->sendTemplate(
                $account,
                $to,
                $template->name,
                $template->language,
                $components
            );

            $wamid = data_get($response, 'messages.0.id');
            $message->forceFill([
                'wa_message_id' => $wamid,
                'status' => WhatsAppMessage::STATUS_SENT,
                'raw_payload' => $response,
            ])->save();

            $recipient->forceFill([
                'status' => WhatsAppCampaignRecipient::STATUS_SENT,
                'whatsapp_message_id' => $message->id,
                'wa_message_id' => $wamid,
                'sent_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'retriable' => false,
            ])->save();

            $conversation->forceFill([
                'last_message_at' => now(),
                'last_message_preview' => mb_substr($resolved['preview'], 0, 120),
            ])->save();

            return $recipient->fresh();
        });
    }

    protected function ensureConversation(WhatsAppAccount $account, WhatsAppCampaignRecipient $recipient): WhatsAppConversation
    {
        $waId = PhoneNormalizer::digits($recipient->phone ?: $recipient->phone_key);

        return WhatsAppConversation::query()->firstOrCreate(
            [
                'whatsapp_account_id' => $account->id,
                'contact_wa_id' => $waId,
            ],
            [
                'company_id' => $account->company_id,
                'contact_phone' => $waId,
                'contact_name' => $recipient->customer_name,
                'status' => WhatsAppConversation::STATUS_OPEN,
            ]
        );
    }

    protected function markFailed(WhatsAppCampaignRecipient $recipient, string $code, string $message, bool $retriable): void
    {
        $recipient->forceFill([
            'status' => WhatsAppCampaignRecipient::STATUS_FAILED,
            'failed_at' => now(),
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 2000),
            'retriable' => $retriable,
        ])->save();
    }

    protected function isRetriable(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, '429')
            || str_contains($msg, 'rate')
            || str_contains($msg, 'timeout')
            || str_contains($msg, '503')
            || str_contains($msg, '502')
            || str_contains($msg, 'temporarily');
    }
}
