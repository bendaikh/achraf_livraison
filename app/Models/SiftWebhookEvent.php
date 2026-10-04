<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Sift webhook delivery (idempotency + troubleshooting). */
class SiftWebhookEvent extends Model
{
    protected $fillable = [
        'company_id', 'event_id', 'event_type', 'status', 'tracking_number', 'parcel_id', 'sift_shipment_id', 'order_id',
        'payload', 'headers', 'message', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'headers' => 'array', 'processed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event_type' => $this->event_type,
            'status' => $this->status,
            'tracking_number' => $this->tracking_number,
            'order_id' => $this->order_id,
            'order_reference' => $this->order?->reference(),
            'message' => $this->message,
            'headers' => $this->headers,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
