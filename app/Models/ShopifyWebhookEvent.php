<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyWebhookEvent extends Model
{
    protected $fillable = [
        'company_id', 'shopify_shop_id', 'topic', 'webhook_id', 'event_id', 'resource_id',
        'triggered_at', 'payload', 'status', 'attempts', 'error', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'triggered_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopifyShop::class, 'shopify_shop_id');
    }
}
