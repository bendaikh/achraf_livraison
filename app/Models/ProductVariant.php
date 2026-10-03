<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'company_id', 'shopify_variant_id', 'shopify_inventory_item_id', 'title', 'sku', 'barcode',
        'price', 'compare_at_price', 'inventory_quantity', 'inventory_levels', 'inventory_tracked', 'inventory_policy',
        'image_url', 'position',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'inventory_quantity' => 'integer',
            'inventory_levels' => 'array',
            'inventory_tracked' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeForCompany(Builder $q, int $companyId): Builder
    {
        return $q->where('product_variants.company_id', $companyId);
    }

    /** "Default Title" is Shopify's name for a product without options. */
    public function displayTitle(): ?string
    {
        return $this->title && $this->title !== 'Default Title' ? $this->title : null;
    }

    /** Variant image first, then the product's main image (placeholder handled by the UI). */
    public function imageUrl(): ?string
    {
        return $this->image_url ?: $this->product?->image_url;
    }

    /** Stock is only meaningful when Shopify tracks the inventory of this variant. */
    public function isOutOfStock(): bool
    {
        return $this->inventory_tracked && (int) $this->inventory_quantity <= 0;
    }

    public function stockLabel(): string
    {
        if (! $this->inventory_tracked) {
            return 'Stock non suivi';
        }

        return (int) $this->inventory_quantity > 0 ? ((int) $this->inventory_quantity).' en stock' : 'Rupture de stock';
    }

    /** Shopify allows selling this variant when out of stock ("Continuer à vendre"). */
    public function allowsOversell(): bool
    {
        return ! $this->inventory_tracked || $this->inventory_policy === 'continue';
    }

    public function toCatalogArray(): array
    {
        $product = $this->product;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'shopify_product_id' => $product?->shopify_product_id,
            'shopify_variant_id' => $this->shopify_variant_id,
            'title' => $product?->title,
            'variant_title' => $this->displayTitle(),
            'sku' => $this->sku,
            'price' => (float) $this->price,
            'compare_at_price' => $this->compare_at_price !== null ? (float) $this->compare_at_price : null,
            'inventory_quantity' => $this->inventory_quantity,
            'inventory_tracked' => (bool) $this->inventory_tracked,
            'out_of_stock' => $this->isOutOfStock(),
            'allows_oversell' => $this->allowsOversell(),
            'stock_label' => $this->stockLabel(),
            'image' => $this->imageUrl(),
            'status' => $product?->status,
            'status_label' => $product?->statusLabel(),
            'collections' => $product?->collections ?? [],
            'source' => $product?->shop?->shop_name ?? $product?->shop?->shop_domain ?? ($product?->source === 'shopify' ? 'Shopify' : 'Manuel'),
            'source_label' => $product?->shop ? 'Shopify · '.($product->shop->shop_name ?: $product->shop->shop_domain) : ($product?->source === 'shopify' ? 'Shopify' : 'Manuel'),
        ];
    }
}
