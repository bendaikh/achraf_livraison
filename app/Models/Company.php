<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function whatsappAccounts(): HasMany
    {
        return $this->hasMany(WhatsAppAccount::class);
    }

    public function logisticsPartners(): HasMany
    {
        return $this->hasMany(LogisticsPartner::class);
    }

    public static function default(): self
    {
        return static::query()->firstOrCreate(
            ['slug' => 'lavfast-flow'],
            ['name' => 'Lavfast Flow', 'is_active' => true]
        );
    }
}
