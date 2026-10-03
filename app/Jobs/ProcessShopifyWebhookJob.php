<?php

namespace App\Jobs;

use App\Models\ShopifyShop;
use App\Services\Shopify\CatalogSyncService;
use App\Services\Shopify\OrderSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessShopifyWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $topic,
        public string $shopDomain,
        public array $payload,
    ) {}

    public function handle(OrderSyncService $sync, CatalogSyncService $catalog): void
    {
        $shop = ShopifyShop::query()
            ->where('shop_domain', $this->shopDomain)
            ->first();

        if (! $shop && $this->topic !== 'shop/redact') {
            Log::warning('Shopify webhook for unknown shop', [
                'shop' => $this->shopDomain,
                'topic' => $this->topic,
            ]);

            return;
        }

        match ($this->topic) {
            'orders/create', 'orders/updated' => $sync->upsertFromShopifyPayload($shop, $this->payload),
            'orders/cancelled' => $sync->markCancelled($shop, $this->payload),
            // Product catalog (T4)
            'products/create', 'products/update' => $catalog->handleProductWebhook($shop, $this->payload),
            'products/delete' => $catalog->handleProductDeleted($shop, $this->payload),
            'inventory_levels/update' => $catalog->handleInventoryLevel($shop, $this->payload),
            'app/uninstalled' => $this->handleUninstall($shop),
            'customers/data_request', 'customers/redact' => null,
            'shop/redact' => $this->handleShopRedact($shop),
            default => Log::info('Unhandled Shopify webhook topic', ['topic' => $this->topic]),
        };

        if ($shop && in_array($this->topic, ['orders/create', 'orders/updated', 'orders/cancelled'], true)) {
            $shop->forceFill(['last_synced_at' => now()])->save();
        }
    }

    private function handleUninstall(ShopifyShop $shop): void
    {
        $shop->forceFill([
            'is_active' => false,
            'uninstalled_at' => now(),
            'access_token' => null,
        ])->save();
    }

    private function handleShopRedact(?ShopifyShop $shop): void
    {
        if (! $shop) {
            return;
        }

        $shop->orders()->delete();
        $shop->delete();
    }
}
