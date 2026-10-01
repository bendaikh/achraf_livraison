<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookProcessor
{
    public function __construct(
        protected ConversationOrderLinker $linker,
        protected WhatsAppCloudClient $client,
    ) {}

    public function process(array $payload): void
    {
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $metadata = $value['metadata'] ?? [];
                $phoneNumberId = (string) ($metadata['phone_number_id'] ?? '');

                $account = WhatsAppAccount::query()
                    ->where('phone_number_id', $phoneNumberId)
                    ->where('is_active', true)
                    ->first();

                if (! $account) {
                    Log::info('WhatsApp webhook for unknown phone_number_id', ['phone_number_id' => $phoneNumberId]);

                    continue;
                }

                $contacts = collect($value['contacts'] ?? [])->keyBy('wa_id');

                foreach ($value['messages'] ?? [] as $message) {
                    $this->ingestInboundMessage($account, $message, $contacts->get($message['from'] ?? '') ?? []);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->ingestStatus($account, $status);
                }
            }
        }
    }

    protected function ingestInboundMessage(WhatsAppAccount $account, array $message, array $contact): void
    {
        $waMessageId = (string) ($message['id'] ?? '');
        if ($waMessageId === '') {
            return;
        }

        $eventKey = 'msg:'.$waMessageId;
        if (WhatsAppWebhookEvent::query()->where('event_key', $eventKey)->exists()) {
            return;
        }

        DB::transaction(function () use ($account, $message, $contact, $waMessageId, $eventKey) {
            WhatsAppWebhookEvent::query()->create([
                'event_key' => $eventKey,
                'phone_number_id' => $account->phone_number_id,
                'event_type' => 'message',
                'payload' => $message,
                'processed_at' => now(),
            ]);

            if (WhatsAppMessage::query()->where('wa_message_id', $waMessageId)->exists()) {
                return;
            }

            $from = PhoneNormalizer::digits((string) ($message['from'] ?? ''));
            $profileName = data_get($contact, 'profile.name');

            $conversation = WhatsAppConversation::query()->firstOrCreate(
                [
                    'whatsapp_account_id' => $account->id,
                    'contact_wa_id' => $from,
                ],
                [
                    'company_id' => $account->company_id,
                    'contact_phone' => $from,
                    'contact_name' => $profileName,
                    'status' => WhatsAppConversation::STATUS_OPEN,
                ]
            );

            if ($profileName && $conversation->contact_name !== $profileName) {
                $conversation->contact_name = $profileName;
            }

            $parsed = $this->parseMessageContent($message);
            $metaTs = isset($message['timestamp'])
                ? \Carbon\Carbon::createFromTimestamp((int) $message['timestamp'])
                : now();

            $mediaPath = null;
            if (! empty($parsed['media_id']) && $account->access_token) {
                try {
                    $downloaded = $this->client->downloadMedia($account, $parsed['media_id']);
                    $mediaPath = $downloaded['path'] ?? null;
                    $parsed['media_mime'] = $downloaded['mime'] ?? $parsed['media_mime'];
                } catch (\Throwable $e) {
                    Log::warning('WhatsApp media download failed', ['error' => $e->getMessage()]);
                }
            }

            WhatsAppMessage::query()->create([
                'company_id' => $account->company_id,
                'whatsapp_conversation_id' => $conversation->id,
                'whatsapp_account_id' => $account->id,
                'direction' => WhatsAppMessage::DIRECTION_INBOUND,
                'type' => $parsed['type'],
                'body' => $parsed['body'],
                'media_id' => $parsed['media_id'],
                'media_mime' => $parsed['media_mime'],
                'media_filename' => $parsed['media_filename'],
                'media_path' => $mediaPath,
                'latitude' => $parsed['latitude'],
                'longitude' => $parsed['longitude'],
                'location_name' => $parsed['location_name'],
                'wa_message_id' => $waMessageId,
                'status' => WhatsAppMessage::STATUS_DELIVERED,
                'meta_timestamp' => $metaTs,
                'raw_payload' => $message,
            ]);

            $conversation->forceFill([
                'contact_phone' => $conversation->contact_phone ?: $from,
                'unread_count' => $conversation->unread_count + 1,
                'last_message_preview' => $parsed['preview'],
                'last_message_at' => $metaTs,
                'last_inbound_at' => $metaTs,
                'status' => $conversation->status === WhatsAppConversation::STATUS_RESOLVED
                    ? WhatsAppConversation::STATUS_OPEN
                    : $conversation->status,
            ])->save();

            $this->linker->link($conversation);
        });
    }

    protected function ingestStatus(WhatsAppAccount $account, array $status): void
    {
        $waMessageId = (string) ($status['id'] ?? '');
        if ($waMessageId === '') {
            return;
        }

        $statusValue = (string) ($status['status'] ?? '');
        $eventKey = 'status:'.$waMessageId.':'.$statusValue.':'.($status['timestamp'] ?? '');

        if (WhatsAppWebhookEvent::query()->where('event_key', $eventKey)->exists()) {
            return;
        }

        WhatsAppWebhookEvent::query()->create([
            'event_key' => $eventKey,
            'phone_number_id' => $account->phone_number_id,
            'event_type' => 'status',
            'payload' => $status,
            'processed_at' => now(),
        ]);

        $message = WhatsAppMessage::query()->where('wa_message_id', $waMessageId)->first();
        if (! $message) {
            return;
        }

        $rank = [
            WhatsAppMessage::STATUS_PENDING => 0,
            WhatsAppMessage::STATUS_SENT => 1,
            WhatsAppMessage::STATUS_DELIVERED => 2,
            WhatsAppMessage::STATUS_READ => 3,
            WhatsAppMessage::STATUS_FAILED => 4,
        ];

        $mapped = match ($statusValue) {
            'sent' => WhatsAppMessage::STATUS_SENT,
            'delivered' => WhatsAppMessage::STATUS_DELIVERED,
            'read' => WhatsAppMessage::STATUS_READ,
            'failed' => WhatsAppMessage::STATUS_FAILED,
            default => null,
        };

        if (! $mapped) {
            return;
        }

        $currentRank = $rank[$message->status] ?? -1;
        $newRank = $rank[$mapped] ?? -1;

        // Do not regress status (except failed always wins)
        if ($mapped !== WhatsAppMessage::STATUS_FAILED && $newRank < $currentRank) {
            return;
        }

        $error = data_get($status, 'errors.0');

        $message->forceFill([
            'status' => $mapped,
            'status_timestamp' => isset($status['timestamp'])
                ? \Carbon\Carbon::createFromTimestamp((int) $status['timestamp'])
                : now(),
            'error_code' => data_get($error, 'code'),
            'error_message' => data_get($error, 'title') ?: data_get($error, 'message'),
        ])->save();
    }

    protected function parseMessageContent(array $message): array
    {
        $type = (string) ($message['type'] ?? 'text');

        $result = [
            'type' => $type,
            'body' => null,
            'preview' => '['.$type.']',
            'media_id' => null,
            'media_mime' => null,
            'media_filename' => null,
            'latitude' => null,
            'longitude' => null,
            'location_name' => null,
        ];

        return match ($type) {
            'text' => array_merge($result, [
                'body' => data_get($message, 'text.body'),
                'preview' => (string) data_get($message, 'text.body'),
            ]),
            'image' => array_merge($result, [
                'body' => data_get($message, 'image.caption'),
                'preview' => data_get($message, 'image.caption') ?: '📷 Photo',
                'media_id' => data_get($message, 'image.id'),
                'media_mime' => data_get($message, 'image.mime_type'),
            ]),
            'video' => array_merge($result, [
                'body' => data_get($message, 'video.caption'),
                'preview' => data_get($message, 'video.caption') ?: '🎬 Vidéo',
                'media_id' => data_get($message, 'video.id'),
                'media_mime' => data_get($message, 'video.mime_type'),
            ]),
            'audio', 'voice' => array_merge($result, [
                'type' => $type === 'voice' ? 'audio' : $type,
                'preview' => '🎤 Audio',
                'media_id' => data_get($message, $type.'.id') ?: data_get($message, 'audio.id'),
                'media_mime' => data_get($message, $type.'.mime_type') ?: data_get($message, 'audio.mime_type'),
            ]),
            'document' => array_merge($result, [
                'body' => data_get($message, 'document.caption'),
                'preview' => data_get($message, 'document.filename') ?: '📄 Document',
                'media_id' => data_get($message, 'document.id'),
                'media_mime' => data_get($message, 'document.mime_type'),
                'media_filename' => data_get($message, 'document.filename'),
            ]),
            'location' => array_merge($result, [
                'preview' => '📍 '.((string) (data_get($message, 'location.name') ?: 'Localisation')),
                'latitude' => data_get($message, 'location.latitude'),
                'longitude' => data_get($message, 'location.longitude'),
                'location_name' => data_get($message, 'location.name') ?: data_get($message, 'location.address'),
            ]),
            'button' => array_merge($result, [
                'body' => data_get($message, 'button.text'),
                'preview' => (string) data_get($message, 'button.text'),
            ]),
            'interactive' => array_merge($result, [
                'body' => data_get($message, 'interactive.button_reply.title')
                    ?: data_get($message, 'interactive.list_reply.title'),
                'preview' => (string) (
                    data_get($message, 'interactive.button_reply.title')
                    ?: data_get($message, 'interactive.list_reply.title')
                    ?: 'Réponse interactive'
                ),
            ]),
            default => array_merge($result, [
                'type' => 'unsupported',
                'preview' => 'Message non supporté ('.$type.')',
            ]),
        };
    }
}
