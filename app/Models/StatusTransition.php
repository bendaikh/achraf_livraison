<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusTransition extends Model
{
    protected $fillable = ['from_status_id', 'to_status_id'];

    public function from()
    {
        return $this->belongsTo(DeliveryStatus::class, 'from_status_id');
    }

    public function to()
    {
        return $this->belongsTo(DeliveryStatus::class, 'to_status_id');
    }
}
