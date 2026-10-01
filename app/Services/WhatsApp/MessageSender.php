<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageSender
{
    public function __construct(
        protected WhatsAppCloudClient $client,
    ) {}

    public function sendText(WhatsAppConversation $conversation, string $text, User $user): WhatsAppMessage
    {
        $account = $conversation->account;
        if (! $account || ! $account->isConnected()) {
            throw new \RuntimeException('Le numéro WhatsApp n’est pas connecté.');
        }

        $to = PhoneNormalizer::digits($conversation->contact_wa_id);

        return DB::transaction(function () use ($conversation, $account, $text, $user, $to) {
            $message = WhatsAppMessage::query()->create([
                'company_id' => $conversation->company_id,
                'whatsapp_conversation_id' => $conversation->id,
                'whatsapp_account_id' => $account->id,
                'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
                'type' => 'text',
                'body' => $text,
                'status' => WhatsAppMessage::STATUS_PENDING,
                'sent_by_user_id' => $user->id,
                'meta_timestamp' => now(),
            ]);

            try {
                $response = $this->client->sendText($account, $to, $text);
                $wamid = data_get($response, 'messages.0.id');
                $message->forceFill([
                    'wa_message_id' => $wamid,
                    'status' => WhatsAppMessage::STATUS_SENT,
                    'raw_payload' => $response,
                ])->save();
            } catch (\Throwable $e) {
                $message->forceFill([
                    'status' => WhatsAppMessage::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ])->save();
                throw $e;
            }

            $this->touchConversation($conversation, $text);

            return $message->fresh(['sentBy']);
        });
    }

    public function sendTemplate(
        WhatsAppConversation $conversation,
        WhatsAppTemplate $template,
        array $bodyVariables,
        User $user
    ): WhatsAppMessage {
        $account = $conversation->account;
        if (! $account || ! $account->isConnected()) {
            throw new \RuntimeException('Le numéro WhatsApp n’est pas connecté.');
        }

        if (! $template->isApproved()) {
            throw new \RuntimeException('Ce template n’est pas encore approuvé par Meta.');
        }

        $components = [];
        if ($bodyVariables !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($value) => ['type' => 'text', 'text' => (string) $value],
                    array_values($bodyVariables)
                ),
            ];
        }

        $to = PhoneNormalizer::digits($conversation->contact_wa_id);
        $preview = $this->renderTemplatePreview($template->body_text ?? $template->name, $bodyVariables);

        return DB::transaction(function () use ($conversation, $account, $template, $components, $user, $to, $preview, $bodyVariables) {
            $message = WhatsAppMessage::query()->create([
                'company_id' => $conversation->company_id,
                'whatsapp_conversation_id' => $conversation->id,
                'whatsapp_account_id' => $account->id,
                'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
                'type' => 'template',
                'body' => $preview,
                'template_name' => $template->name,
                'template_language' => $template->language,
                'template_components' => $bodyVariables,
                'status' => WhatsAppMessage::STATUS_PENDING,
                'sent_by_user_id' => $user->id,
                'meta_timestamp' => now(),
            ]);

            try {
                $response = $this->client->sendTemplate(
                    $account,
                    $to,
                    $template->name,
                    $template->language,
                    $components
                );
                $message->forceFill([
                    'wa_message_id' => data_get($response, 'messages.0.id'),
                    'status' => WhatsAppMessage::STATUS_SENT,
                    'raw_payload' => $response,
                ])->save();
            } catch (\Throwable $e) {
                $message->forceFill([
                    'status' => WhatsAppMessage::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ])->save();
                throw $e;
            }

            $this->touchConversation($conversation, $preview);

            return $message->fresh(['sentBy']);
        });
    }

    public function sendUploadedMedia(
        WhatsAppConversation $conversation,
        string $absolutePath,
        string $mime,
        string $type,
        ?string $caption,
        ?string $filename,
        User $user
    ): WhatsAppMessage {
        $account = $conversation->account;
        if (! $account || ! $account->isConnected()) {
            throw new \RuntimeException('Le numéro WhatsApp n’est pas connecté.');
        }

        $to = PhoneNormalizer::digits($conversation->contact_wa_id);
        $preview = $caption ?: match ($type) {
            'image' => '📷 Photo',
            'video' => '🎬 Vidéo',
            'audio' => '🎤 Audio',
            'document' => $filename ?: '📄 Document',
            default => 'Média',
        };

        $message = WhatsAppMessage::query()->create([
            'company_id' => $conversation->company_id,
            'whatsapp_conversation_id' => $conversation->id,
            'whatsapp_account_id' => $account->id,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => $type,
            'body' => $caption,
            'media_mime' => $mime,
            'media_filename' => $filename,
            'media_path' => null,
            'status' => WhatsAppMessage::STATUS_PENDING,
            'sent_by_user_id' => $user->id,
            'meta_timestamp' => now(),
        ]);

        try {
            $upload = $this->client->uploadMedia($account, $absolutePath, $mime);
            $mediaId = (string) ($upload['id'] ?? '');
            $response = $this->client->sendMediaById($account, $to, $type, $mediaId, $caption, $filename);

            $relative = 'whatsapp/'.$account->company_id.'/out_'.$message->id.'_'.basename($absolutePath);
            \Illuminate\Support\Facades\Storage::disk('local')->put(
                $relative,
                file_get_contents($absolutePath)
            );

            $message->forceFill([
                'media_id' => $mediaId,
                'media_path' => $relative,
                'wa_message_id' => data_get($response, 'messages.0.id'),
                'status' => WhatsAppMessage::STATUS_SENT,
                'raw_payload' => $response,
            ])->save();
        } catch (\Throwable $e) {
            $message->forceFill([
                'status' => WhatsAppMessage::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ])->save();
            Log::error('WhatsApp media send failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        $this->touchConversation($conversation, $preview);

        return $message->fresh(['sentBy']);
    }

    protected function touchConversation(WhatsAppConversation $conversation, string $preview): void
    {
        $conversation->forceFill([
            'last_message_preview' => mb_substr($preview, 0, 500),
            'last_message_at' => now(),
            'status' => $conversation->status === WhatsAppConversation::STATUS_RESOLVED
                ? WhatsAppConversation::STATUS_OPEN
                : $conversation->status,
        ])->save();
    }

    protected function renderTemplatePreview(string $body, array $variables): string
    {
        $result = $body;
        foreach (array_values($variables) as $index => $value) {
            $result = str_replace('{{'.($index + 1).'}}', (string) $value, $result);
        }

        return $result;
    }
}
