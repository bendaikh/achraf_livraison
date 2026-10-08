<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFulfillment extends Model
{
    protected $fillable = [
        'company_id', 'order_id', 'shopify_fulfillment_id', 'status',
        'tracking_number', 'tracking_company', 'tracking_url', 'source', 'shopify_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'shopify_synced_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
