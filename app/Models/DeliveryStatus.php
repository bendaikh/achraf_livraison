<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryStatus extends Model
{
    protected $fillable = ['name', 'code', 'color', 'icon', 'sort_order', 'is_active', 'category'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public static function idsForCategories(array $categories): array
    {
        return static::query()->whereIn('category', $categories)->pluck('id')->all();
    }
}
