<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'city', 'vehicle', 'is_active', 'notes'];

    protected $casts = ['is_active' => 'boolean'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function missions()
    {
        return $this->hasMany(Mission::class);
    }
}
