<?php

namespace App\Models;

use App\Services\Sift\SiftStatusMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Sift.ma parcel (parcelId / trackingNumber / customOrderNo) created from a Commande. */
class SiftShipment extends Model
{
    public const STATE_CREATED = 'created';

    public const STATE_DELIVERED = 'delivered';

    public const STATE_RETURNED = 'returned';

    public const STATE_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'order_id', 'parcel_id', 'tracking_number', 'custom_order_no', 'state', 'receiver', 'phone', 'city',
        'address', 'cod_amount', 'allow_open', 'raw_status', 'raw_sub_status', 'raw_status_comment', 'status_at',
        'mapped_status', 'history', 'reused_existing', 'request_payload', 'create_response', 'last_response', 'last_error',
        'last_synced_at', 'cancelled_at', 'cancelled_by', 'hidden_at', 'hidden_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cod_amount' => 'decimal:2',
            'allow_open' => 'boolean',
            'reused_existing' => 'boolean',
            'history' => 'array',
            'request_payload' => 'array',
            'create_response' => 'array',
            'last_response' => 'array',
            'status_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->state !== self::STATE_CANCELLED && ! $this->hidden_at;
    }

    /** Sift only accepts PUT edits while the parcel is pending. */
    public function isEditable(): bool
    {
        return $this->state === self::STATE_CREATED && filled($this->parcel_id)
            && in_array(SiftStatusMap::key($this->raw_status ?: 'pending'), SiftStatusMap::EDITABLE, true);
    }

    public function scopeActive($q)
    {
        return $q->where('state', '!=', self::STATE_CANCELLED)->whereNull('hidden_at');
    }

    public function scopeTrackable($q)
    {
        return $q->where('state', self::STATE_CREATED)->whereNull('hidden_at')->where(fn ($w) => $w->whereNotNull('parcel_id')->orWhereNotNull('tracking_number'));
    }

    /** @var array<string, string> */
    protected static array $statusNames = [];

    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'parcel_id' => $this->parcel_id,
            'tracking_number' => $this->tracking_number,
            'custom_order_no' => $this->custom_order_no,
            'state' => $this->state,
            'receiver' => $this->receiver,
            'phone' => $this->phone,
            'city' => $this->city,
            'address' => $this->address,
            'cod_amount' => $this->cod_amount !== null ? (float) $this->cod_amount : null,
            'allow_open' => $this->allow_open,
            'raw_status' => $this->raw_status,
            'raw_status_label' => SiftStatusMap::label($this->raw_status),
            'raw_sub_status' => $this->raw_sub_status,
            'raw_status_comment' => $this->raw_status_comment,
            'status_at' => $this->status_at?->toIso8601String(),
            'mapped_status' => $this->mapped_status,
            'mapped_status_name' => $this->mapped_status ? (self::$statusNames[$this->mapped_status] ??= (string) (DeliveryStatus::query()->where('code', $this->mapped_status)->value('name') ?? $this->mapped_status)) : null,
            'history' => array_values($this->history ?? []),
            'reused_existing' => (bool) $this->reused_existing,
            'editable' => $this->isEditable(),
            'can_cancel' => $this->state === self::STATE_CREATED && ! $this->hidden_at && filled($this->parcel_id),
            'can_hide' => ! $this->hidden_at && $this->state !== self::STATE_CREATED,
            'hidden_at' => $this->hidden_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
