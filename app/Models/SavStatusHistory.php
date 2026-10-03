<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavStatusHistory extends Model
{
    protected $fillable = ['sav_request_id', 'from_status', 'to_status', 'event', 'label', 'comment', 'user_id', 'driver_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
