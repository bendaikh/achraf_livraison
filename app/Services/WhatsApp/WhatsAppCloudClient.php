<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class WhatsAppCloudClient
{
    public function __construct(
        protected MetaOAuth $meta,
    ) {}

    protected function http(WhatsAppAccount $account): PendingRequest
    {
        if (! filled($account->access_token)) {
            throw new \RuntimeException('Compte WhatsApp sans token d’accès.');
        }

        return Http::withToken($account->access_token)
            ->baseUrl('https://graph.facebook.com/'.$this->meta->graphVersion())
            ->acceptJson()
            ->timeout(30);
    }

    public function sendText(WhatsAppAccount $account, string $to, string $text): array
    {
        $response = $this->http($account)->post('/'.$account->phone_number_id.'/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->extractError($response->json(), $response->body()));
        }

        return $response->json();
    }

    public function sendTemplate(
        WhatsAppAccount $account,
        string $to,
        string $templateName,
        string $language,
        array $components = []
    ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
            ],
        ];

        if ($components !== []) {
            $payload['template']['components'] = $components;
        }

        $response = $this->http($account)->post('/'.$account->phone_number_id.'/messages', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException($this->extractError($response->json(), $response->body()));
        }

        return $response->json();
    }

    public function sendMedia(
        WhatsAppAccount $account,
        string $to,
        string $type,
        string $link,
        ?string $caption = null,
        ?string $filename = null
    ): array {
        $media = ['link' => $link];
        if ($caption !== null && $caption !== '' && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }
        if ($filename && $type === 'document') {
            $media['filename'] = $filename;
        }

        $response = $this->http($account)->post('/'.$account->phone_number_id.'/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $type,
            $type => $media,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->extractError($response->json(), $response->body()));
        }

        return $response->json();
    }

    public function uploadMedia(WhatsAppAccount $account, string $absolutePath, string $mime): array
    {
        $response = $this->http($account)
            ->attach('file', file_get_contents($absolutePath), basename($absolutePath))
            ->post('/'.$account->phone_number_id.'/media', [
                'messaging_product' => 'whatsapp',
                'type' => $mime,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->extractError($response->json(), $response->body()));
        }

        return $response->json();
    }

    public function sendMediaById(
        WhatsAppAccount $account,
        string $to,
        string $type,
        string $mediaId,
        ?string $caption = null,
        ?string $filename = null
    ): array {
        $media = ['id' => $mediaId];
        if ($caption !== null && $caption !== '' && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }
        if ($filename && $type === 'document') {
            $media['filename'] = $filename;
        }

        $response = $this->http($account)->post('/'.$account->phone_number_id.'/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => $type,
            $type => $media,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->extractError($response->json(), $response->body()));
        }

        return $response->json();
    }

    public function downloadMedia(WhatsAppAccount $account, string $mediaId): array
    {
        $meta = $this->http($account)->get('/'.$mediaId);
        if (! $meta->successful()) {
            throw new \RuntimeException('Impossible de récupérer les métadonnées média Meta.');
        }

        $url = $meta->json('url');
        $mime = $meta->json('mime_type');
        if (! $url) {
            throw new \RuntimeException('URL média Meta manquante.');
        }

        $binary = Http::withToken($account->access_token)->timeout(60)->get($url);
        if (! $binary->successful()) {
            throw new \RuntimeException('Téléchargement média Meta échoué.');
        }

        $ext = match (true) {
            str_contains((string) $mime, 'jpeg'), str_contains((string) $mime, 'jpg') => 'jpg',
            str_contains((string) $mime, 'png') => 'png',
            str_contains((string) $mime, 'webp') => 'webp',
            str_contains((string) $mime, 'mp4') => 'mp4',
            str_contains((string) $mime, 'ogg'), str_contains((string) $mime, 'opus') => 'ogg',
            str_contains((string) $mime, 'pdf') => 'pdf',
            default => 'bin',
        };

        $path = 'whatsapp/'.$account->company_id.'/'.$mediaId.'.'.$ext;
        Storage::disk('local')->put($path, $binary->body());

        return [
            'path' => $path,
            'mime' => $mime,
            'size' => $meta->json('file_size'),
        ];
    }

    public function listMessageTemplates(WhatsAppAccount $account): array
    {
        if (! filled($account->waba_id)) {
            return [];
        }

        $response = $this->http($account)->get('/'.$account->waba_id.'/message_templates', [
            'limit' => 100,
            'fields' => 'id,name,language,status,category,components',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->extractError($response->json(), $response->body()));
        }

        return $response->json('data') ?? [];
    }

    public function createMessageTemplate(WhatsAppAccount $account, array $payload): array
    {
        if (! filled($account->waba_id)) {
            throw new \RuntimeException('WABA ID manquant pour créer un template.');
        }

        $response = $this->http($account)->post('/'.$account->waba_id.'/message_templates', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException($this->extractError($response->json(), $response->body()));
        }

        return $response->json();
    }

    public function fetchPhoneNumbers(string $wabaId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://graph.facebook.com/'.$this->meta->graphVersion().'/'.$wabaId.'/phone_numbers', [
                'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->extractError($response->json(), $response->body()));
        }

        return $response->json('data') ?? [];
    }

    public function fetchSharedWabas(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://graph.facebook.com/'.$this->meta->graphVersion().'/me/businesses', [
                'fields' => 'id,name,owned_whatsapp_business_accounts{id,name,phone_numbers{id,display_phone_number,verified_name}}',
            ]);

        if (! $response->successful()) {
            // Fallback: debug token + granular scopes often expose WABA via /debug_token
            return [];
        }

        return $response->json('data') ?? [];
    }

    protected function extractError(?array $json, string $fallback): string
    {
        $message = data_get($json, 'error.message')
            ?: data_get($json, 'error.error_user_msg')
            ?: $fallback;

        return is_string($message) ? $message : 'Erreur API WhatsApp Meta.';
    }
}
