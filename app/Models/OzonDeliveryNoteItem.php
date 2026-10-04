<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzonDeliveryNoteItem extends Model
{
    protected $fillable = ['ozon_delivery_note_id', 'ozon_shipment_id', 'order_id', 'tracking_number'];

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(OzonDeliveryNote::class, 'ozon_delivery_note_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(OzonShipment::class, 'ozon_shipment_id');
    }
}
