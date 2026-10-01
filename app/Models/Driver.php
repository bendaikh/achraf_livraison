<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Commandes actuellement chez le livreur (attribuées / en cours / reportées). */
    public function assignedOrdersCount(): int
    {
        return $this->orders()
            ->whereIn('delivery_status', Order::DELIVERY_ACTIVE_STATUSES)
            ->count();
    }

    public function inProgressOrdersCount(): int
    {
        return $this->orders()
            ->whereIn('delivery_status', [
                Order::DELIVERY_IN_PROGRESS,
                Order::DELIVERY_POSTPONED,
            ])
            ->count();
    }

    public function deliveredOrdersCount(): int
    {
        return $this->orders()
            ->where('delivery_status', Order::DELIVERY_DELIVERED)
            ->count();
    }

    /** COD encore détenu par le livreur (pas encore clôturé / remis). */
    public function codHeldAmount(): float
    {
        return (float) $this->orders()
            ->where('delivery_status', Order::DELIVERY_DELIVERED)
            ->whereNull('cod_remitted_at')
            ->sum('amount_collected');
    }
}
