<?php

namespace App\Services\Shopify;

use App\Models\ShopifyAppSetting;
use App\Models\ShopifyShop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ShopifyOAuth
{
    /** Latest stable Admin API (2025-01 is out of support). */
    public const API_VERSION = '2026-10';

    /**
     * Two-way sync. write_* implies the matching read_*.
     * Verified against Shopify access scopes (2026-10): write_order_edits,
     * read/write_merchant_managed_fulfillment_orders, read/write_fulfillments all exist.
     */
    public const DEFAULT_SCOPES = 'read_orders,write_orders,write_order_edits,read_customers,write_customers,read_products,write_products,read_inventory,write_inventory,read_locations,read_merchant_managed_fulfillment_orders,write_merchant_managed_fulfillment_orders,read_fulfillments,write_fulfillments';

    /** Topics registered idempotently (REST webhooks.json, supported on 2026-10). */
    public const WEBHOOK_TOPICS = [
        'orders/create',
        'orders/updated',
        'orders/cancelled',
        'orders/edited',
        'orders/paid',
        'orders/fulfilled',
        'orders/partially_fulfilled',
        'products/create',
        'products/update',
        'products/delete',
        'inventory_levels/update',
        'fulfillments/create',
        'fulfillments/update',
        'refunds/create',
        'customers/create',
        'customers/update',
        'app/uninstalled',
        'customers/data_request',
        'customers/redact',
        'shop/redact',
    ];

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
        $settings = $this->settings();
        $fromDb = $settings->requested_scopes ?: $settings->scopes;
        $legacy = [
            'read_orders,read_customers',
            'read_orders,read_customers,read_products,read_inventory',
        ];
        if (! filled($fromDb) || in_array($fromDb, $legacy, true)) {
            return (string) config('services.shopify.scopes', self::DEFAULT_SCOPES);
        }

        return $fromDb;
    }

    public function apiVersion(): string
    {
        $fromDb = $this->settings()->api_version;

        return filled($fromDb)
            ? $fromDb
            : (string) config('services.shopify.api_version', self::API_VERSION);
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

        $topics = self::WEBHOOK_TOPICS;

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
