<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'reference', 'product_name', 'product_image', 'quantity', 'customer_name', 'customer_phone',
        'city', 'address', 'amount', 'payment_method', 'confirmation_status', 'delivery_status_id',
        'driver_id', 'carrier', 'assigned_user_id', 'source', 'note', 'status_reason', 'postponed_at',
        'collected_amount', 'confirmed_at', 'delivered_at', 'status_changed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'collected_amount' => 'decimal:2',
        'quantity' => 'integer',
        'postponed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'status_changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (Order $order) {
            if (! $order->reference) {
                $order->reference = 'CMD-'.(1000 + $order->id);
                $order->saveQuietly();
            }
        });
    }

    public function deliveryStatus()
    {
        return $this->belongsTo(DeliveryStatus::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function missions()
    {
        return $this->hasMany(Mission::class);
    }

    public function histories()
    {
        return $this->hasMany(OrderStatusHistory::class)->orderByDesc('created_at')->orderByDesc('id');
    }
}
