<?php

namespace App\Services\Shopify;

use App\Models\ShopifyShop;
use Carbon\Carbon;

class ShopifyReconcileService
{
    public function __construct(
        private readonly ShopifyOrderNormalizer $orders,
        private readonly OrderSyncService $sync,
        private readonly CatalogSyncService $catalog,
    ) {}

    /**
     * @return array{orders:int,skipped:int,dry_run:bool}
     */
    public function reconcile(ShopifyShop $shop, ?Carbon $since = null, bool $dryRun = false, bool $deep = false): array
    {
        if (! $shop->isInstalled() || ! $shop->hasScope('read_orders')) {
            return ['orders' => 0, 'skipped' => 0, 'dry_run' => $dryRun];
        }

        $cursor = $since;
        if (! $cursor) {
            $cursor = $deep
                ? now()->subDays(7)
                : ($shop->orders_reconciled_at ? $shop->orders_reconciled_at->copy()->subMinutes(10) : now()->subDays(2));
        }
        $query = "updated_at:>='".$cursor->utc()->format('Y-m-d\TH:i:s\Z')."'";
        $client = $this->orders->client($shop);
        $count = 0;
        $skipped = 0;
        $after = null;

        do {
            $data = $client->graphql(ShopifyOrderNormalizer::ORDERS_QUERY, [
                'first' => 25,
                'after' => $after,
                'query' => $query,
            ]);
            $page = $data['orders'] ?? [];
            foreach ($page['nodes'] ?? [] as $node) {
                $count++;
                if ($dryRun) {
                    continue;
                }
                $before = $this->sync->lastOutcome;
                $this->sync->upsertFromShopifyPayload($shop, $this->orders->toRest($node), 'reconcile');
                if ($this->sync->lastOutcome === 'ignored') {
                    $skipped++;
                }
                unset($before);
            }
            $after = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
        } while ($after);

        if (! $dryRun) {
            $shop->forceFill(['orders_reconciled_at' => now(), 'last_synced_at' => now()])->save();
            if ($shop->hasScope('read_products')) {
                try {
                    $this->catalog->sync($shop, false);
                } catch (\Throwable $e) {
                    // Catalog errors are stored on the shop by CatalogSyncService.
                }
            }
        }

        return ['orders' => $count, 'skipped' => $skipped, 'dry_run' => $dryRun];
    }
}
