<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifySyncLog extends Model
{
    protected $fillable = [
        'company_id', 'shopify_shop_id', 'direction', 'entity_type', 'entity_id', 'shopify_id',
        'action', 'source', 'user_id', 'status', 'attempts', 'error', 'request_excerpt', 'response_excerpt',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopifyShop::class, 'shopify_shop_id');
    }
}
