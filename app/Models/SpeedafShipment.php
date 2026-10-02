<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Speedaf waybill (billCode) created from a Commande, with the raw API exchanges. */
class SpeedafShipment extends Model
{
    public const STATE_CREATED = 'created';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_DELIVERED = 'delivered';

    public const STATE_RETURNED = 'returned';

    protected $fillable = [
        'company_id', 'order_id', 'environment', 'bill_code', 'custom_order_no', 'state',
        'last_action', 'last_sub_action', 'last_action_name', 'last_message', 'last_event_at',
        'label_url', 'request_payload', 'create_response', 'tracks', 'last_response', 'last_error',
        'last_synced_at', 'cancelled_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'create_response' => 'array',
            'tracks' => 'array',
            'last_response' => 'array',
            'last_event_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isActive(): bool
    {
        return $this->state !== self::STATE_CANCELLED;
    }

    /** Still worth polling (not cancelled, not in a final state). */
    public function isTrackable(): bool
    {
        return $this->state === self::STATE_CREATED && filled($this->bill_code);
    }

    public function scopeTrackable($q)
    {
        return $q->where('state', self::STATE_CREATED)->whereNotNull('bill_code');
    }

    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'bill_code' => $this->bill_code,
            'environment' => $this->environment,
            'state' => $this->state,
            'status_code' => $this->last_action,
            'sub_status_code' => $this->last_sub_action,
            'status_label' => $this->last_action_name,
            'status_message' => $this->last_message,
            'status_at' => $this->last_event_at?->toIso8601String(),
            'label_url' => $this->label_url,
            'last_error' => $this->last_error,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
