<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyFieldChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'shopify_shop_id', 'entity_type', 'entity_id', 'field',
        'old_value', 'new_value', 'source', 'user_id', 'sync_log_id', 'conflict', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'conflict' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
