<?php

namespace App\Services\Shopify;

use App\Models\ShopifyAppSetting;
use App\Models\ShopifyShop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ShopifyOAuth
{
    public function settings(): ShopifyAppSetting
    {
        return ShopifyAppSetting::current();
    }

    public function clientId(): ?string
    {
        $fromDb = $this->settings()->client_id;

        return filled($fromDb) ? $fromDb : config('services.shopify.client_id');
    }

    public function clientSecret(): ?string
    {
        $fromDb = $this->settings()->client_secret;

        return filled($fromDb) ? $fromDb : config('services.shopify.client_secret');
    }

    public function scopes(): string
    {
        $fromDb = $this->settings()->scopes;

        return filled($fromDb)
            ? $fromDb
            : (string) config('services.shopify.scopes', 'read_orders,read_customers');
    }

    public function apiVersion(): string
    {
        $fromDb = $this->settings()->api_version;

        return filled($fromDb)
            ? $fromDb
            : (string) config('services.shopify.api_version', '2025-01');
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function authorizationUrl(string $shopDomain, string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId(),
            'scope' => $this->scopes(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);

        return "https://{$shopDomain}/admin/oauth/authorize?{$query}";
    }

    public function redirectUri(): string
    {
        return rtrim(config('app.url'), '/').'/shopify/callback';
    }

    public function webhookUrl(): string
    {
        return rtrim(config('app.url'), '/').'/shopify/webhooks';
    }

    public function verifyQueryHmac(array $params): bool
    {
        if (! isset($params['hmac'])) {
            return false;
        }

        $hmac = $params['hmac'];
        unset($params['hmac'], $params['signature']);

        ksort($params);
        $encoded = [];
        foreach ($params as $key => $value) {
            $encoded[] = $key.'='.(is_array($value) ? implode(',', $value) : $value);
        }

        $message = implode('&', $encoded);
        $calculated = hash_hmac('sha256', $message, (string) $this->clientSecret());

        return hash_equals($calculated, $hmac);
    }

    public function verifyWebhookHmac(string $rawBody, ?string $hmacHeader): bool
    {
        if (! $hmacHeader) {
            return false;
        }

        $calculated = base64_encode(
            hash_hmac('sha256', $rawBody, (string) $this->clientSecret(), true)
        );

        return hash_equals($calculated, $hmacHeader);
    }

    public function exchangeCode(string $shopDomain, string $code): array
    {
        $response = Http::asForm()->post("https://{$shopDomain}/admin/oauth/access_token", [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Impossible d’échanger le code OAuth Shopify.');
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new RuntimeException('Token d’accès Shopify manquant.');
        }

        return $data;
    }

    public function createNonce(): string
    {
        return Str::random(40);
    }

    public function registerWebhooks(ShopifyShop $shop): void
    {
        $client = new ShopifyClient($shop->shop_domain, $shop->access_token, $this->apiVersion());
        $address = $this->webhookUrl();

        $topics = [
            'orders/create',
            'orders/updated',
            'orders/cancelled',
            'app/uninstalled',
            'customers/data_request',
            'customers/redact',
            'shop/redact',
        ];

        $existing = $client->get('webhooks.json');
        $existingTopics = collect($existing['webhooks'] ?? [])
            ->pluck('topic')
            ->all();

        foreach ($topics as $topic) {
            if (in_array($topic, $existingTopics, true)) {
                continue;
            }

            $client->post('webhooks.json', [
                'webhook' => [
                    'topic' => $topic,
                    'address' => $address,
                    'format' => 'json',
                ],
            ]);
        }
    }

    public function fetchShopDetails(ShopifyShop $shop): array
    {
        $client = new ShopifyClient($shop->shop_domain, $shop->access_token, $this->apiVersion());
        $payload = $client->get('shop.json');

        return $payload['shop'] ?? [];
    }
}
