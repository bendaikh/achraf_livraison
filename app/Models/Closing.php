<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Closing extends Model
{
    protected $fillable = [
        'driver_id', 'closing_date', 'cod_expected', 'cod_remitted', 'commissions_total',
        'orders_count', 'missions_count', 'note', 'user_id', 'closed_at',
    ];

    protected $casts = [
        'closing_date' => 'date:Y-m-d',
        'closed_at' => 'datetime',
        'cod_expected' => 'decimal:2',
        'cod_remitted' => 'decimal:2',
        'commissions_total' => 'decimal:2',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
