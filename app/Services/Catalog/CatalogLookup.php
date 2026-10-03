<?php

namespace App\Services\Catalog;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\CurrentUser;

/**
 * Links order lines to the synced catalog (company-scoped) to show product photos and stock
 * everywhere (Commandes, fiche commande, Confirmation). Image priority: variant image →
 * product image → image stored on the line → null (the UI shows a clean placeholder).
 * Batched: prime() loads everything needed for a page of orders in 2 queries.
 * Registered as a scoped singleton (one cache per request).
 */
class CatalogLookup
{
    /** @var array<int, ProductVariant> */
    protected array $byVariantId = [];

    /** @var array<string, ProductVariant> */
    protected array $bySku = [];

    /** @var array<int, Product> */
    protected array $byProductId = [];

    /** @var array<int, ProductVariant> local id */
    protected array $byLocalId = [];

    protected array $loadedVariantIds = [];

    protected array $loadedSkus = [];

    protected array $loadedProductIds = [];

    protected ?int $companyId = null;

    protected function companyId(): ?int
    {
        return $this->companyId ??= CurrentUser::get()?->resolveCompanyId();
    }

    /** @param  iterable<Order>  $orders */
    public function prime(iterable $orders): void
    {
        $variantIds = $skus = $productIds = $localIds = [];
        foreach ($orders as $order) {
            foreach ((array) ($order->line_items ?? []) as $line) {
                if (! empty($line['variant_id'])) {
                    $variantIds[] = (int) $line['variant_id'];
                }
                if (! empty($line['sku'])) {
                    $skus[] = (string) $line['sku'];
                }
                if (! empty($line['product_id'])) {
                    $productIds[] = (int) $line['product_id'];
                }
                if (! empty($line['catalog_variant_id'])) {
                    $localIds[] = (int) $line['catalog_variant_id'];
                }
            }
        }
        $this->load(array_unique($variantIds), array_unique($skus), array_unique($productIds), array_unique($localIds));
    }

    protected function load(array $variantIds, array $skus, array $productIds, array $localIds = []): void
    {
        $companyId = $this->companyId();
        if (! $companyId) {
            return;
        }
        $variantIds = array_values(array_diff($variantIds, $this->loadedVariantIds));
        $skus = array_values(array_diff($skus, $this->loadedSkus));
        $productIds = array_values(array_diff($productIds, $this->loadedProductIds));
        $localIds = array_values(array_diff($localIds, array_keys($this->byLocalId)));
        $this->loadedVariantIds = array_merge($this->loadedVariantIds, $variantIds);
        $this->loadedSkus = array_merge($this->loadedSkus, $skus);
        $this->loadedProductIds = array_merge($this->loadedProductIds, $productIds);

        if ($variantIds || $skus || $localIds) {
            ProductVariant::query()->forCompany($companyId)->with('product.shop')
                ->where(function ($q) use ($variantIds, $skus, $localIds) {
                    $q->whereIn('shopify_variant_id', $variantIds ?: [0])
                        ->orWhereIn('sku', $skus ?: ['__none__'])
                        ->orWhereIn('id', $localIds ?: [0]);
                })->get()
                ->each(function (ProductVariant $v) {
                    $this->byLocalId[$v->id] = $v;
                    if ($v->shopify_variant_id) {
                        $this->byVariantId[(int) $v->shopify_variant_id] = $v;
                    }
                    if ($v->sku && ! isset($this->bySku[$v->sku])) {
                        $this->bySku[$v->sku] = $v;
                    }
                });
        }
        if ($productIds) {
            Product::query()->forCompany($companyId)->whereIn('shopify_product_id', $productIds)->get()
                ->each(fn (Product $p) => $this->byProductId[(int) $p->shopify_product_id] = $p);
        }
    }

    public function variantFor(array $line): ?ProductVariant
    {
        $this->load(
            ! empty($line['variant_id']) ? [(int) $line['variant_id']] : [],
            ! empty($line['sku']) ? [(string) $line['sku']] : [],
            ! empty($line['product_id']) ? [(int) $line['product_id']] : [],
            ! empty($line['catalog_variant_id']) ? [(int) $line['catalog_variant_id']] : [],
        );

        return (! empty($line['catalog_variant_id']) ? ($this->byLocalId[(int) $line['catalog_variant_id']] ?? null) : null)
            ?? (! empty($line['variant_id']) ? ($this->byVariantId[(int) $line['variant_id']] ?? null) : null)
            ?? (! empty($line['sku']) ? ($this->bySku[(string) $line['sku']] ?? null) : null);
    }

    /** Line + catalog data (image, stock…) for display. Never persisted. */
    public function enrich(array $line): array
    {
        $variant = $this->variantFor($line);
        $product = $variant?->product ?? (! empty($line['product_id']) ? ($this->byProductId[(int) $line['product_id']] ?? null) : null);
        $lineImage = is_array($line['image'] ?? null) ? ($line['image']['src'] ?? null) : ($line['image'] ?? null);

        return $line + [
            'image_url' => $variant?->image_url ?: ($product?->image_url ?: $lineImage),
            'catalog_variant_id' => $variant?->id,
            'stock_label' => $variant?->stockLabel(),
            'inventory_quantity' => $variant?->inventory_quantity,
            'inventory_tracked' => $variant ? (bool) $variant->inventory_tracked : null,
            'out_of_stock' => $variant?->isOutOfStock(),
            'catalog_price' => $variant ? (float) $variant->price : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function enrichOrder(Order $order): array
    {
        return array_map(fn ($l) => $this->enrich($l), OrderLines::normalize($order->line_items));
    }

    public function firstImage(Order $order): ?string
    {
        foreach (OrderLines::normalize($order->line_items) as $line) {
            if ($img = $this->enrich($line)['image_url']) {
                return $img;
            }
        }

        return null;
    }

    /** True when a line's catalog variant is out of stock (Centre → Rupture de stock). */
    public function hasOutOfStock(Order $order): bool
    {
        foreach (OrderLines::normalize($order->line_items) as $line) {
            if ($this->enrich($line)['out_of_stock']) {
                return true;
            }
        }

        return false;
    }
}
