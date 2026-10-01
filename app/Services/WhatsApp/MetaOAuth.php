<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MetaOAuth
{
    public function settings(): WhatsAppAppSetting
    {
        return WhatsAppAppSetting::current();
    }

    public function isConfigured(): bool
    {
        return $this->settings()->isConfigured()
            || (filled(config('services.meta.app_id')) && filled(config('services.meta.app_secret')));
    }

    public function appId(): ?string
    {
        return $this->settings()->app_id ?: config('services.meta.app_id');
    }

    public function appSecret(): ?string
    {
        return $this->settings()->app_secret ?: config('services.meta.app_secret');
    }

    public function configId(): ?string
    {
        return $this->settings()->config_id ?: config('services.meta.config_id');
    }

    public function graphVersion(): string
    {
        return $this->settings()->graph_api_version
            ?: config('services.meta.graph_api_version', 'v21.0');
    }

    public function webhookVerifyToken(): string
    {
        return (string) ($this->settings()->webhook_verify_token
            ?: config('services.meta.webhook_verify_token')
            ?: '');
    }

    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/whatsapp/meta/callback';
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/whatsapp/webhooks';
    }

    public function createNonce(): string
    {
        return Str::random(40);
    }

    /**
     * Classic Facebook OAuth URL for WhatsApp Business Platform.
     */
    public function authorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', [
                'whatsapp_business_management',
                'whatsapp_business_messaging',
                'business_management',
            ]),
        ]);

        return 'https://www.facebook.com/'.$this->graphVersion().'/dialog/oauth?'.$params;
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->get('https://graph.facebook.com/'.$this->graphVersion().'/oauth/access_token', [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Meta token exchange failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Exchange Embedded Signup short-lived code for a long-lived token.
     */
    public function exchangeEmbeddedSignupCode(string $code): array
    {
        $response = Http::asForm()->post('https://graph.facebook.com/'.$this->graphVersion().'/oauth/access_token', [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Meta Embedded Signup exchange failed: '.$response->body());
        }

        return $response->json();
    }

    public function debugToken(string $inputToken): array
    {
        $response = Http::get('https://graph.facebook.com/'.$this->graphVersion().'/debug_token', [
            'input_token' => $inputToken,
            'access_token' => $this->appId().'|'.$this->appSecret(),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Meta debug_token failed: '.$response->body());
        }

        return $response->json('data') ?? [];
    }

    public function verifyWebhookChallenge(string $mode, string $token, string $challenge): ?string
    {
        if ($mode === 'subscribe' && hash_equals($this->webhookVerifyToken(), $token)) {
            return $challenge;
        }

        return null;
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = $this->appSecret();
        if (! $secret || ! $signatureHeader) {
            return false;
        }

        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
