<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryStatus extends Model
{
    protected $fillable = ['name', 'code', 'color', 'icon', 'sort_order', 'is_active', 'category', 'required_fields', 'creates_mission_type'];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
        'color' => '#64748b',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'required_fields' => 'array',
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

    public function transitionsFrom()
    {
        return $this->hasMany(StatusTransition::class, 'from_status_id');
    }

    public function histories()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** A status referenced by an order, the history or a transition must be deactivated, never deleted. */
    public function isUsed(): bool
    {
        return $this->orders()->exists()
            || $this->histories()->exists()
            || OrderStatusHistory::query()->where('from_status_id', $this->id)->exists();
    }

    public function requiredFields(): array
    {
        return array_values($this->required_fields ?? []);
    }

    public static function idsForCategories(array $categories): array
    {
        return static::query()->whereIn('category', $categories)->pluck('id')->all();
    }
}
