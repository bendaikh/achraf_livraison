<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Centre de confirmation — « Remises » granted on an order (internal, never pushed to Shopify). */
class OrderDiscount extends Model
{
    protected $fillable = ['order_id', 'user_id', 'type', 'value', 'amount', 'reason', 'removed_at', 'removed_by', 'shopify_discount_ids'];

    protected function casts(): array
    {
        return ['value' => 'float', 'amount' => 'float', 'removed_at' => 'datetime', 'shopify_discount_ids' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'value' => $this->value,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'user_name' => $this->user?->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'removed_at' => $this->removed_at?->toIso8601String(),
        ];
    }
}
