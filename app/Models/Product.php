<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalog product synced from the company's Shopify store (Shopify is the source of truth;
 * images are referenced by their Shopify CDN URL). Unique per store + Shopify product id.
 */
class Product extends Model
{
    public const STATUSES = ['active' => 'Actif', 'draft' => 'Brouillon', 'archived' => 'Archivé'];

    protected $fillable = [
        'company_id', 'shopify_shop_id', 'source', 'shopify_product_id', 'title', 'handle', 'vendor', 'product_type',
        'status', 'image_url', 'images', 'collections', 'tags', 'shopify_updated_at', 'synced_at', 'deleted_in_shopify_at',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'collections' => 'array',
            'shopify_updated_at' => 'datetime',
            'synced_at' => 'datetime',
            'deleted_in_shopify_at' => 'datetime',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopifyShop::class, 'shopify_shop_id');
    }

    public function scopeForCompany(Builder $q, int $companyId): Builder
    {
        return $q->where('products.company_id', $companyId);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
