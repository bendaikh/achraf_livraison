<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyShop extends Model
{
    protected $fillable = [
        'shop_domain',
        'access_token',
        'scopes',
        'shop_name',
        'shop_email',
        'currency',
        'timezone',
        'is_active',
        'installed_at',
        'uninstalled_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'is_active' => 'boolean',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isInstalled(): bool
    {
        return $this->is_active && filled($this->access_token) && $this->uninstalled_at === null;
    }
}
