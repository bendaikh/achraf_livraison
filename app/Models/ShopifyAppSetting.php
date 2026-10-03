<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyAppSetting extends Model
{
    protected $fillable = [
        'client_id',
        'client_secret',
        'scopes',
        'api_version',
    ];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'scopes' => config('services.shopify.scopes', 'read_orders,read_customers,read_products,read_inventory'),
                'api_version' => config('services.shopify.api_version', '2025-01'),
            ]
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }
}
