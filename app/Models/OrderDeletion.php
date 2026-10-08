<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit row for a soft-deleted draft. The order row is kept. */
class OrderDeletion extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'user_id', 'reason', 'snapshot', 'created_at'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
