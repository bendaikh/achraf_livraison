<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusHistory extends Model
{
    protected $fillable = [
        'order_id', 'kind', 'delivery_status_id', 'status_code', 'status_name', 'status_color', 'status_category',
        'from_status_id', 'from_status_name', 'data', 'note', 'user_id',
    ];

    protected $casts = ['data' => 'array'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
