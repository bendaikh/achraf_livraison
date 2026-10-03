<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** T7 — Retour / échange request linked to a delivered order. */
class SavRequest extends Model
{
    protected $fillable = [
        'company_id', 'reference', 'order_id', 'type', 'status', 'reason', 'comment', 'sav_note',
        'customer_name', 'phone', 'address', 'city', 'amount_paid', 'original_driver_id', 'original_delivered_at',
        'driver_id', 'mission_id', 'driver_fee', 'assigned_at', 'postponed_until', 'picked_up_at', 'new_delivered_at',
        'received_at', 'received_by', 'closed_at', 'closed_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2', 'driver_fee' => 'decimal:2',
            'original_delivered_at' => 'datetime', 'assigned_at' => 'datetime', 'postponed_until' => 'datetime',
            'picked_up_at' => 'datetime', 'new_delivered_at' => 'datetime', 'received_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (SavRequest $s) {
            if (! $s->reference) {
                $s->reference = ($s->type === 'echange' ? 'ECH' : 'RET').'-'.str_pad((string) $s->id, 5, '0', STR_PAD_LEFT);
                $s->saveQuietly();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function originalDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'original_driver_id');
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SavRequestItem::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SavStatusHistory::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public static function statusLabel(?string $s): string
    {
        return config("sav.statuses.$s.label") ?? (string) $s;
    }

    public static function statusColor(?string $s): string
    {
        return config("sav.statuses.$s.color") ?? '#64748b';
    }
}
