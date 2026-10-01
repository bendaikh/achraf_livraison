<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionStatusHistory extends Model
{
    protected $fillable = ['mission_id', 'event', 'status', 'label', 'note', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
