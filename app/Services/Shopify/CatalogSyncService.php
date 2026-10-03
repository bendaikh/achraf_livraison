<?php

namespace App\Services\Shopify;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopifyShop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shopify → Lav'Fast Flow product catalog (one-way, Shopify is the source of truth).
 *  - full / incremental sync through the Admin GraphQL API (manual button + scheduler);
 *  - products/create|update|delete and inventory_levels/update webhooks (REST payloads).
 * Upserts by store + Shopify product id / variant id → never duplicates. Images are kept as
 * Shopify CDN URLs. Requires the read_products (+ read_inventory for stock webhooks) scopes.
 */
class CatalogSyncService
{
    public const PAGE_SIZE = 10;

    private const PRODUCTS_QUERY = <<<'GQL'
query Catalog($first: Int!, $after: String, $query: String) {
  products(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
    pageInfo { hasNextPage endCursor }
    nodes {
      legacyResourceId title handle status vendor productType tags updatedAt
      featuredImage { url }
      images(first: 5) { nodes { url } }
      collections(first: 5) { nodes { title } }
      variants(first: 60) {
        pageInfo { hasNextPage }
        nodes {
          legacyResourceId title sku barcode price compareAtPrice inventoryQuantity inventoryPolicy position
          image { url }
          inventoryItem { legacyResourceId tracked }
        }
      }
    }
  }
}
GQL;

    private const VARIANTS_QUERY = <<<'GQL'
query Variants($id: ID!, $after: String) {
  product(id: $id) {
    variants(first: 100, after: $after) {
      pageInfo { hasNextPage endCursor }
      nodes {
        legacyResourceId title sku barcode price compareAtPrice inventoryQuantity inventoryPolicy position
        image { url }
        inventoryItem { legacyResourceId tracked }
      }
    }
  }
}
GQL;

    public function __construct(protected ShopifyOAuth $oauth) {}

    protected function client(ShopifyShop $shop): ShopifyClient
    {
        if (! $shop->isInstalled()) {
            throw new RuntimeException('Boutique Shopify non connectée.');
        }

        return new ShopifyClient($shop->shop_domain, $shop->access_token, $this->oauth->apiVersion());
    }

    /**
     * Pulls the catalog. Incremental (updated since the last catalog sync) unless $full, which
     * also flags products that disappeared from Shopify.
     *
     * @return array{products:int, variants:int, full:bool}
     */
    public function sync(ShopifyShop $shop, bool $full = false): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $client = $this->client($shop);
        $startedAt = now();
        $since = ! $full && $shop->catalog_synced_at ? $shop->catalog_synced_at->copy()->subMinutes(5) : null;
        $query = $since ? "updated_at:>='".$since->utc()->format('Y-m-d\TH:i:s\Z')."'" : null;

        $seen = [];
        $products = 0;
        $variants = 0;
        $after = null;
        try {
            do {
                $data = $client->graphql(self::PRODUCTS_QUERY, ['first' => self::PAGE_SIZE, 'after' => $after, 'query' => $query]);
                $page = $data['products'] ?? [];
                foreach ($page['nodes'] ?? [] as $node) {
                    $normalized = $this->fromGraphql($node);
                    if (! empty($node['variants']['pageInfo']['hasNextPage'])) {
                        $normalized['variants'] = $this->allVariants($client, $node);
                    }
                    $product = $this->upsert($shop, $normalized);
                    $seen[] = $product->shopify_product_id;
                    $products++;
                    $variants += count($normalized['variants']);
                }
                $after = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
            } while ($after);

            if ($full) {
                // Products no longer returned by Shopify: kept for history (orders), flagged + archived.
                Product::query()->where('shopify_shop_id', $shop->id)->whereNotIn('shopify_product_id', $seen ?: [0])
                    ->whereNull('deleted_in_shopify_at')
                    ->update(['deleted_in_shopify_at' => now(), 'status' => 'archived']);
            }
            $shop->forceFill(['catalog_synced_at' => $startedAt, 'catalog_sync_error' => null])->save();
        } catch (\Throwable $e) {
            $shop->forceFill(['catalog_sync_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            throw $e;
        }

        return ['products' => $products, 'variants' => $variants, 'full' => $full];
    }

    /** Scheduler entry point: incremental sync of every connected store with the products scope. */
    public function syncAll(): void
    {
        ShopifyShop::query()->where('is_active', true)->whereNull('uninstalled_at')->get()
            ->filter(fn (ShopifyShop $s) => $s->isInstalled() && $s->hasScope('read_products'))
            ->each(function (ShopifyShop $shop) {
                try {
                    // Full pass once a day (detects deleted products), incremental otherwise.
                    $full = ! $shop->catalog_synced_at || $shop->catalog_synced_at->lt(now()->subDay());
                    $this->sync($shop, $full);
                } catch (\Throwable $e) {
                    Log::warning('Shopify catalog sync failed', ['shop' => $shop->shop_domain, 'error' => $e->getMessage()]);
                }
            });
    }

    protected function allVariants(ShopifyClient $client, array $node): array
    {
        $out = [];
        $after = null;
        $gid = 'gid://shopify/Product/'.$node['legacyResourceId'];
        do {
            $data = $client->graphql(self::VARIANTS_QUERY, ['id' => $gid, 'after' => $after]);
            $conn = $data['product']['variants'] ?? [];
            foreach ($conn['nodes'] ?? [] as $v) {
                $out[] = $this->variantFromGraphql($v);
            }
            $after = ($conn['pageInfo']['hasNextPage'] ?? false) ? ($conn['pageInfo']['endCursor'] ?? null) : null;
        } while ($after);

        return $out;
    }

    /* ---------------------------------------------------------------- webhooks (REST payloads) */

    public function handleProductWebhook(ShopifyShop $shop, array $payload): ?Product
    {
        if (empty($payload['id'])) {
            return null;
        }

        return $this->upsert($shop, $this->fromRest($payload, $this->existingCollections($shop, (int) $payload['id'])));
    }

    public function handleProductDeleted(ShopifyShop $shop, array $payload): void
    {
        if (empty($payload['id'])) {
            return;
        }
        Product::query()->where('shopify_shop_id', $shop->id)->where('shopify_product_id', (int) $payload['id'])
            ->update(['deleted_in_shopify_at' => now(), 'status' => 'archived']);
    }

    /** inventory_levels/update: {inventory_item_id, location_id, available}. */
    public function handleInventoryLevel(ShopifyShop $shop, array $payload): int
    {
        $itemId = (int) ($payload['inventory_item_id'] ?? 0);
        if (! $itemId) {
            return 0;
        }
        $variants = ProductVariant::query()->where('shopify_inventory_item_id', $itemId)
            ->whereHas('product', fn ($q) => $q->where('shopify_shop_id', $shop->id))->get();
        foreach ($variants as $variant) {
            $levels = (array) ($variant->inventory_levels ?? []);
            $levels[(string) ($payload['location_id'] ?? 'default')] = (int) ($payload['available'] ?? 0);
            $variant->forceFill([
                'inventory_levels' => $levels,
                'inventory_quantity' => array_sum($levels),
                'inventory_tracked' => true,
            ])->save();
        }

        return $variants->count();
    }

    /* ---------------------------------------------------------------- normalisation + upsert */

    /** @return array<string, mixed> */
    public function fromGraphql(array $node): array
    {
        return [
            'shopify_product_id' => (int) $node['legacyResourceId'],
            'title' => (string) ($node['title'] ?? 'Produit'),
            'handle' => $node['handle'] ?? null,
            'vendor' => $node['vendor'] ?? null,
            'product_type' => $node['productType'] ?? null,
            'status' => strtolower((string) ($node['status'] ?? 'active')),
            'image_url' => $node['featuredImage']['url'] ?? ($node['images']['nodes'][0]['url'] ?? null),
            'images' => array_values(array_filter(array_map(fn ($i) => $i['url'] ?? null, $node['images']['nodes'] ?? []))),
            'collections' => array_values(array_filter(array_map(fn ($c) => $c['title'] ?? null, $node['collections']['nodes'] ?? []))),
            'tags' => is_array($node['tags'] ?? null) ? implode(', ', $node['tags']) : ($node['tags'] ?? null),
            'shopify_updated_at' => $node['updatedAt'] ?? null,
            'variants' => array_map(fn ($v) => $this->variantFromGraphql($v), $node['variants']['nodes'] ?? []),
        ];
    }

    protected function variantFromGraphql(array $v): array
    {
        return [
            'shopify_variant_id' => (int) $v['legacyResourceId'],
            'shopify_inventory_item_id' => isset($v['inventoryItem']['legacyResourceId']) ? (int) $v['inventoryItem']['legacyResourceId'] : null,
            'title' => $v['title'] ?? null,
            'sku' => ($v['sku'] ?? '') !== '' ? $v['sku'] : null,
            'barcode' => $v['barcode'] ?? null,
            'price' => (float) ($v['price'] ?? 0),
            'compare_at_price' => isset($v['compareAtPrice']) && $v['compareAtPrice'] !== null ? (float) $v['compareAtPrice'] : null,
            'inventory_quantity' => isset($v['inventoryQuantity']) ? (int) $v['inventoryQuantity'] : null,
            'inventory_tracked' => (bool) ($v['inventoryItem']['tracked'] ?? false),
            'inventory_policy' => strtolower((string) ($v['inventoryPolicy'] ?? 'deny')),
            'image_url' => $v['image']['url'] ?? null,
            'position' => (int) ($v['position'] ?? 1),
        ];
    }

    /** REST product payload (webhooks). */
    public function fromRest(array $p, array $collections = []): array
    {
        $images = collect($p['images'] ?? []);
        $imageById = $images->mapWithKeys(fn ($i) => [(string) ($i['id'] ?? '') => $i['src'] ?? null]);

        return [
            'shopify_product_id' => (int) $p['id'],
            'title' => (string) ($p['title'] ?? 'Produit'),
            'handle' => $p['handle'] ?? null,
            'vendor' => $p['vendor'] ?? null,
            'product_type' => $p['product_type'] ?? null,
            'status' => strtolower((string) ($p['status'] ?? 'active')),
            'image_url' => $p['image']['src'] ?? ($images->first()['src'] ?? null),
            'images' => $images->pluck('src')->filter()->values()->all(),
            'collections' => $collections,
            'tags' => $p['tags'] ?? null,
            'shopify_updated_at' => $p['updated_at'] ?? null,
            'variants' => array_map(fn ($v) => [
                'shopify_variant_id' => (int) $v['id'],
                'shopify_inventory_item_id' => isset($v['inventory_item_id']) ? (int) $v['inventory_item_id'] : null,
                'title' => $v['title'] ?? null,
                'sku' => ($v['sku'] ?? '') !== '' ? $v['sku'] : null,
                'barcode' => $v['barcode'] ?? null,
                'price' => (float) ($v['price'] ?? 0),
                'compare_at_price' => isset($v['compare_at_price']) && $v['compare_at_price'] !== null && $v['compare_at_price'] !== '' ? (float) $v['compare_at_price'] : null,
                'inventory_quantity' => isset($v['inventory_quantity']) ? (int) $v['inventory_quantity'] : null,
                'inventory_tracked' => ($v['inventory_management'] ?? null) === 'shopify',
                'inventory_policy' => strtolower((string) ($v['inventory_policy'] ?? 'deny')),
                'image_url' => ! empty($v['image_id']) ? ($imageById[(string) $v['image_id']] ?? null) : null,
                'position' => (int) ($v['position'] ?? 1),
            ], $p['variants'] ?? []),
        ];
    }

    protected function existingCollections(ShopifyShop $shop, int $productId): array
    {
        return (array) (Product::query()->where('shopify_shop_id', $shop->id)->where('shopify_product_id', $productId)->value('collections') ?? []);
    }

    public function upsert(ShopifyShop $shop, array $data): Product
    {
        $companyId = $shop->resolveCompanyId();

        return DB::transaction(function () use ($shop, $data, $companyId) {
            $variants = $data['variants'] ?? [];
            unset($data['variants']);
            $data['shopify_updated_at'] = ! empty($data['shopify_updated_at']) ? Carbon::parse($data['shopify_updated_at']) : null;

            $product = Product::query()->updateOrCreate(
                ['shopify_shop_id' => $shop->id, 'shopify_product_id' => $data['shopify_product_id']],
                $data + ['company_id' => $companyId, 'source' => 'shopify', 'synced_at' => now(), 'deleted_in_shopify_at' => null],
            );

            $keep = [];
            foreach ($variants as $v) {
                $variant = ProductVariant::query()->updateOrCreate(
                    ['product_id' => $product->id, 'shopify_variant_id' => $v['shopify_variant_id']],
                    $v + ['company_id' => $companyId],
                );
                $keep[] = $variant->id;
            }
            if ($variants !== []) {
                // Variants removed in Shopify (order lines keep their own snapshot, nothing is lost).
                $product->variants()->whereNotIn('id', $keep)->get()->each(fn (ProductVariant $v) => $v->delete());
            }

            return $product;
        });
    }
}
