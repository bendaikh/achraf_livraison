<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mission extends Model
{
    public const PREFIX = [
        'livraison' => 'LIV',
        'ramassage' => 'RAM',
        'depot_partenaire' => 'DEP',
        'retour' => 'RET',
        'echange' => 'ECH',
    ];

    protected $fillable = [
        'reference', 'type', 'status', 'order_id', 'driver_id', 'contact_name', 'phone', 'address', 'city',
        'items_description', 'quantity', 'scheduled_date', 'time_slot', 'cash_amount', 'cash_direction',
        'note', 'completed_at', 'driver_price', 'assigned_at', 'closing_id',
    ];

    protected $attributes = [
        'quantity' => 1,
        'status' => 'a_faire',
    ];

    protected $casts = [
        'scheduled_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
        'assigned_at' => 'datetime',
        'driver_price' => 'decimal:2',
        'cash_amount' => 'decimal:2',
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::created(function (Mission $mission) {
            if (! $mission->reference) {
                $prefix = self::PREFIX[$mission->type] ?? 'MIS';
                $mission->reference = $prefix.'-'.str_pad((string) $mission->id, 5, '0', STR_PAD_LEFT);
                $mission->saveQuietly();
            }
        });
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function histories()
    {
        return $this->hasMany(MissionStatusHistory::class)->orderByDesc('created_at')->orderByDesc('id');
    }
}
