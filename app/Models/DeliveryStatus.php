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

    /** Orders reference the status by its code (orders.delivery_status). */
    public function orders()
    {
        return $this->hasMany(Order::class, 'delivery_status', 'code');
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

    /** Codes (active or not, so old orders still count) of the statuses in these categories. */
    public static function codesForCategories(array $categories): array
    {
        return static::query()->whereIn('category', $categories)->pluck('code')->all();
    }

    public static function findByCode(?string $code): ?self
    {
        if ($code === null || $code === '') {
            return null;
        }

        return static::query()->where('code', $code)->first();
    }

    /** First active status of a category (fallback when no status is configured explicitly). */
    public static function firstActiveOfCategory(string $category): ?self
    {
        return static::query()->active()->where('category', $category)->ordered()->first();
    }
}
