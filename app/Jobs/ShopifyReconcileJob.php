<?php

namespace App\Jobs;

use App\Models\ShopifyShop;
use App\Services\Shopify\ShopifyApiException;
use App\Services\Shopify\ShopifyReconcileService;
use App\Services\Shopify\ShopifySyncLogger;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ShopifyReconcileJob implements ShouldQueue
{
    use HandlesShopifyRetries, Queueable;

    public function __construct(
        public int $shopId,
        public bool $deep = false,
        public ?string $since = null,
        public bool $dryRun = false,
    ) {
        $this->onQueue('shopify');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('shopify-reconcile:'.$this->shopId))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(ShopifyReconcileService $service, ShopifySyncLogger $logger): void
    {
        $shop = ShopifyShop::query()->find($this->shopId);
        if (! $shop || ! $shop->isInstalled()) {
            return;
        }

        try {
            $since = $this->since ? Carbon::parse($this->since) : null;
            $result = $service->reconcile($shop, $since, $this->dryRun, $this->deep);
            $logger->log([
                'company_id' => $shop->company_id,
                'shopify_shop_id' => $shop->id,
                'direction' => 'in',
                'entity_type' => 'order',
                'action' => $this->deep ? 'reconcile_deep' : 'reconcile',
                'source' => 'reconcile',
                'status' => 'success',
                'response_excerpt' => $result,
            ]);
        } catch (ShopifyApiException $e) {
            $logger->log([
                'company_id' => $shop->company_id,
                'shopify_shop_id' => $shop->id,
                'direction' => 'in',
                'entity_type' => 'order',
                'action' => 'reconcile',
                'source' => 'reconcile',
                'status' => 'failed',
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);
            if ($this->releaseForShopify($e)) {
                return;
            }
            $shop->forceFill(['catalog_sync_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            $this->fail($e);
        }
    }
}
