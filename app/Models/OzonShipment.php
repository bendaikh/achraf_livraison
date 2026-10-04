<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An Ozon Express parcel (TRACKING-NUMBER) created from a Commande or an SAV exchange. */
class OzonShipment extends Model
{
    public const STATE_CREATED = 'created';

    public const STATE_DELIVERED = 'delivered';

    public const STATE_RETURNED = 'returned';

    public const STATE_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'order_id', 'sav_request_id', 'kind', 'tracking_number', 'state', 'receiver', 'phone', 'city_id',
        'city_name', 'address', 'price', 'delivered_price', 'returned_price', 'refused_price', 'parcel_stock', 'parcel_open',
        'parcel_fragile', 'parcel_replace', 'raw_status', 'raw_status_comment', 'status_at', 'mapped_status', 'history',
        'delivery_note_id', 'request_payload', 'create_response', 'last_response', 'last_error', 'last_synced_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'delivered_price' => 'decimal:2',
            'returned_price' => 'decimal:2',
            'refused_price' => 'decimal:2',
            'history' => 'array',
            'request_payload' => 'array',
            'create_response' => 'array',
            'last_response' => 'array',
            'status_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function savRequest(): BelongsTo
    {
        return $this->belongsTo(SavRequest::class);
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(OzonDeliveryNote::class, 'delivery_note_id');
    }

    public function isActive(): bool
    {
        return $this->state !== self::STATE_CANCELLED;
    }

    public function scopeActive($q)
    {
        return $q->where('state', '!=', self::STATE_CANCELLED);
    }

    /** Still worth polling. */
    public function scopeTrackable($q)
    {
        return $q->where('state', self::STATE_CREATED)->whereNotNull('tracking_number');
    }

    /** @var array<string, string> code => name (per request) */
    protected static array $statusNames = [];

    public function toSummary(): array
    {
        $note = $this->relationLoaded('deliveryNote') ? $this->deliveryNote : ($this->delivery_note_id ? $this->deliveryNote()->first() : null);

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'sav_request_id' => $this->sav_request_id,
            'tracking_number' => $this->tracking_number,
            'state' => $this->state,
            'receiver' => $this->receiver,
            'phone' => $this->phone,
            'city_id' => $this->city_id,
            'city_name' => $this->city_name,
            'address' => $this->address,
            'price' => $this->price !== null ? (float) $this->price : null,
            'delivered_price' => $this->delivered_price !== null ? (float) $this->delivered_price : null,
            'returned_price' => $this->returned_price !== null ? (float) $this->returned_price : null,
            'refused_price' => $this->refused_price !== null ? (float) $this->refused_price : null,
            'parcel_stock' => $this->parcel_stock,
            'parcel_open' => $this->parcel_open,
            'parcel_fragile' => $this->parcel_fragile,
            'parcel_replace' => $this->parcel_replace,
            'raw_status' => $this->raw_status,
            'raw_status_comment' => $this->raw_status_comment,
            'status_at' => $this->status_at?->toIso8601String(),
            'mapped_status' => $this->mapped_status,
            'mapped_status_name' => $this->mapped_status ? (self::$statusNames[$this->mapped_status] ??= (string) (DeliveryStatus::query()->where('code', $this->mapped_status)->value('name') ?? $this->mapped_status)) : null,
            'history' => array_values($this->history ?? []),
            'delivery_note' => $note ? ['id' => $note->id, 'ref' => $note->ref, 'state' => $note->state, 'documents' => $note->documentUrls()] : null,
            'last_error' => $this->last_error,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
