<?php

namespace App\Jobs;

use App\Models\ShopifyShop;
use App\Models\ShopifyWebhookEvent;
use App\Services\Shopify\CatalogSyncService;
use App\Services\Shopify\OrderSyncService;
use App\Services\Shopify\ShopifyApiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessShopifyWebhookJob implements ShouldQueue
{
    use HandlesShopifyRetries, Queueable;

    public function __construct(
        public string $topic,
        public string $shopDomain,
        public array $payload,
        public ?int $eventId = null,
    ) {
        $this->onQueue('shopify');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $resource = self::resourceId($this->payload) ?? (string) ($this->eventId ?? 'na');

        return [(new WithoutOverlapping('shopify:'.$this->shopDomain.':'.$resource))->releaseAfter(10)->expireAfter(180)];
    }

    /** Order id when the payload is an edit, fulfillment or refund; otherwise the resource id. */
    public static function resourceId(array $payload): ?string
    {
        $id = $payload['order_edit']['order_id'] ?? $payload['order_id'] ?? $payload['id'] ?? null;

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    public function handle(OrderSyncService $sync, CatalogSyncService $catalog): void
    {
        $event = $this->eventId ? ShopifyWebhookEvent::query()->find($this->eventId) : null;
        if ($event) {
            $event->forceFill([
                'status' => 'processing',
                'attempts' => $event->attempts + 1,
            ])->save();
        }

        try {
            $this->process($sync, $catalog, $event);
        } catch (ShopifyApiException $e) {
            $this->markFailed($event, $e->getMessage());
            if ($this->releaseForShopify($e)) {
                return;
            }
            $this->fail($e);
        } catch (\Throwable $e) {
            $this->markFailed($event, $e->getMessage());
            throw $e;
        }
    }

    private function process(OrderSyncService $sync, CatalogSyncService $catalog, ?ShopifyWebhookEvent $event): void
    {
        $shop = ShopifyShop::query()->where('shop_domain', $this->shopDomain)->first();

        if (! $shop && $this->topic !== 'shop/redact') {
            Log::warning('Shopify webhook for unknown shop', [
                'shop' => $this->shopDomain,
                'topic' => $this->topic,
            ]);
            $event?->forceFill(['status' => 'ignored', 'error' => 'Boutique inconnue', 'processed_at' => now()])->save();

            return;
        }

        $payload = $this->payload;
        $payload['_topic'] = $this->topic;

        match ($this->topic) {
            'orders/create', 'orders/updated', 'orders/paid', 'orders/fulfilled', 'orders/partially_fulfilled'
                => $sync->upsertFromShopifyPayload($shop, $payload),
            'orders/edited' => $this->handleEdited($sync, $shop, $payload),
            'orders/cancelled' => $sync->markCancelled($shop, $payload),
            'products/create', 'products/update' => $catalog->handleProductWebhook($shop, $payload),
            'products/delete' => $catalog->handleProductDeleted($shop, $payload),
            'inventory_levels/update' => $catalog->handleInventoryLevel($shop, $payload),
            'fulfillments/create', 'fulfillments/update' => $sync->upsertFulfillmentFromWebhook($shop, $payload),
            'refunds/create' => $sync->applyRefund($shop, $payload),
            'customers/create', 'customers/update' => $sync->applyCustomerUpdate($shop, $payload),
            'app/uninstalled' => $this->handleUninstall($shop),
            'customers/data_request', 'customers/redact' => null,
            'shop/redact' => $this->handleShopRedact($shop),
            default => Log::info('Unhandled Shopify webhook topic', ['topic' => $this->topic]),
        };

        if ($shop && str_starts_with($this->topic, 'orders/')) {
            $shop->forceFill(['last_synced_at' => now()])->save();
        }

        $outcome = match (true) {
            str_starts_with($this->topic, 'orders/') => $sync->lastOutcome,
            str_starts_with($this->topic, 'products/') => $catalog->outcome,
            default => 'processed',
        };
        $event?->forceFill([
            'status' => $outcome === 'ignored' ? 'ignored' : 'processed',
            'error' => $outcome === 'ignored' ? 'Événement plus ancien ignoré' : null,
            'processed_at' => now(),
        ])->save();
    }

    private function handleEdited(OrderSyncService $sync, ShopifyShop $shop, array $payload): void
    {
        if (! empty($payload['line_items']) || isset($payload['total_price'])) {
            $sync->upsertFromShopifyPayload($shop, $payload);

            return;
        }
        $orderId = $payload['order_edit']['order_id'] ?? null;
        if (! $orderId) {
            $sync->lastOutcome = 'processed';

            return;
        }
        $normalizer = app(\App\Services\Shopify\ShopifyOrderNormalizer::class);
        $rest = $normalizer->fetchRest($shop, $orderId);
        if ($rest === null) {
            $sync->lastOutcome = 'ignored';

            return;
        }
        $rest['_topic'] = 'orders/edited';
        $sync->upsertFromShopifyPayload($shop, $rest, 'webhook');
    }

    private function markFailed(?ShopifyWebhookEvent $event, string $message): void
    {
        $event?->forceFill([
            'status' => 'failed',
            'error' => mb_substr($message, 0, 2000),
        ])->save();
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
