<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commission line of a sales / confirmation agent (separate from driver fees and client COD).
 * pending → validated → paid (or cancelled). Rate is a snapshot taken at generation.
 */
class AgentCommission extends Model
{
    public const PENDING = 'pending';

    public const VALIDATED = 'validated';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'user_id', 'order_id', 'period', 'mode', 'trigger', 'rate', 'base_amount', 'amount', 'state',
        'generated_at', 'validated_at', 'validated_by', 'paid_at', 'paid_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'float', 'base_amount' => 'float', 'amount' => 'float',
            'generated_at' => 'datetime', 'validated_at' => 'datetime', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'order_id' => $this->order_id,
            'order_reference' => $this->order?->reference(),
            'order_status' => $this->order?->deliveryStatusLabel(),
            'period' => $this->period,
            'mode' => $this->mode,
            'mode_label' => config("commissions.modes.{$this->mode}.label", $this->mode),
            'trigger_label' => $this->trigger ? config("commissions.triggers.{$this->trigger}", $this->trigger) : null,
            'rate' => $this->rate,
            'base_amount' => $this->base_amount,
            'amount' => $this->amount,
            'state' => $this->state,
            'state_label' => config("commissions.states.{$this->state}", $this->state),
            'generated_at' => $this->generated_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'validated_by_name' => $this->validator?->name,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_by_name' => $this->payer?->name,
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
