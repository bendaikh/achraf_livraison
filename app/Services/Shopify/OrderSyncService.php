<?php

namespace App\Services\Shopify;

use App\Models\ConfirmationStatus;
use App\Models\Order;
use App\Models\ShopifyShop;
use Carbon\Carbon;

class OrderSyncService
{
    public function upsertFromShopifyPayload(ShopifyShop $shop, array $order): Order
    {
        $shipping = $order['shipping_address'] ?? null;
        $customer = $order['customer'] ?? [];

        $customerName = trim(implode(' ', array_filter([
            $customer['first_name'] ?? ($shipping['first_name'] ?? null),
            $customer['last_name'] ?? ($shipping['last_name'] ?? null),
        ])));

        $phone = $order['phone']
            ?? ($shipping['phone'] ?? null)
            ?? ($customer['phone'] ?? null);

        $lineItems = collect($order['line_items'] ?? [])->map(fn (array $item) => [
            'id' => $item['id'] ?? null,
            'title' => $item['title'] ?? null,
            'variant_title' => $item['variant_title'] ?? null,
            'quantity' => $item['quantity'] ?? 0,
            'sku' => $item['sku'] ?? null,
            'price' => $item['price'] ?? null,
            // Catalog link (T4): product photo / stock from the synced Shopify catalog.
            'product_id' => $item['product_id'] ?? null,
            'variant_id' => $item['variant_id'] ?? null,
        ])->values()->all();

        $status = $this->mapLocalStatus($order);
        $shippingPrice = $this->extractShippingPrice($order);

        $existing = Order::query()
            ->where('shopify_shop_id', $shop->id)
            ->where('shopify_order_id', (int) $order['id'])
            ->first();

        $attributes = [
            'order_number' => (string) ($order['order_number'] ?? $order['name'] ?? $order['id']),
            'name' => $order['name'] ?? null,
            'email' => $order['email'] ?? ($customer['email'] ?? null),
            'phone' => $phone,
            'customer_name' => $customerName !== '' ? $customerName : null,
            'financial_status' => $order['financial_status'] ?? null,
            'fulfillment_status' => $order['fulfillment_status'] ?? null,
            'status' => $status,
            'total_price' => $order['total_price'] ?? 0,
            'shipping_price' => $shippingPrice,
            'currency' => $order['currency'] ?? $shop->currency,
            'shipping_address' => $shipping,
            'line_items' => $lineItems,
            'note' => $order['note'] ?? null,
            'shopify_created_at' => $this->parseDate($order['created_at'] ?? null),
            'shopify_updated_at' => $this->parseDate($order['updated_at'] ?? null),
        ];

        // Lines as sent by Shopify are always kept for reference. When the lines were edited inside
        // Lav'Fast Flow (internal change, not pushed to Shopify), a Shopify update must not
        // overwrite them nor the recalculated total.
        $attributes['shopify_line_items'] = $lineItems;
        if ($existing?->items_edited_at) {
            unset($attributes['line_items'], $attributes['total_price']);
        }

        if (! $existing) {
            $attributes['confirmation_status'] = ConfirmationStatus::defaultCode();
            $attributes['confirmation_history'] = [[
                'type' => 'received',
                'label' => 'Commande reçue depuis Shopify',
                'user_id' => null,
                'user_name' => null,
                'at' => now()->toIso8601String(),
            ]];
        }

        return Order::updateOrCreate(
            [
                'shopify_shop_id' => $shop->id,
                'shopify_order_id' => (int) $order['id'],
            ],
            $attributes,
        );
    }

    public function syncRecentOrders(ShopifyShop $shop, int $limit = 50): int
    {
        $apiVersion = app(ShopifyOAuth::class)->apiVersion();
        $client = new ShopifyClient($shop->shop_domain, $shop->access_token, $apiVersion);
        $payload = $client->get('orders.json', [
            'status' => 'any',
            'limit' => min($limit, 250),
            'order' => 'created_at desc',
        ]);

        $count = 0;
        foreach ($payload['orders'] ?? [] as $order) {
            $this->upsertFromShopifyPayload($shop, $order);
            $count++;
        }

        $shop->forceFill(['last_synced_at' => now()])->save();

        return $count;
    }

    public function markCancelled(ShopifyShop $shop, array $order): Order
    {
        $local = $this->upsertFromShopifyPayload($shop, $order);
        $local->forceFill(['status' => 'cancelled'])->save();

        return $local;
    }

    private function mapLocalStatus(array $order): string
    {
        if (! empty($order['cancelled_at'])) {
            return 'cancelled';
        }

        $fulfillment = $order['fulfillment_status'] ?? null;

        return match ($fulfillment) {
            'fulfilled' => 'fulfilled',
            'partial' => 'processing',
            default => 'pending',
        };
    }

    private function extractShippingPrice(array $order): ?float
    {
        $fromSet = data_get($order, 'total_shipping_price_set.shop_money.amount');
        if ($fromSet !== null && $fromSet !== '') {
            return (float) $fromSet;
        }

        $lines = $order['shipping_lines'] ?? [];
        if (! is_array($lines) || $lines === []) {
            return null;
        }

        return (float) collect($lines)->sum(fn ($line) => (float) ($line['price'] ?? 0));
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value);
    }
}
