<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyAppSetting extends Model
{
    protected $fillable = [
        'client_id',
        'client_secret',
        'scopes',
        'requested_scopes',
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
                'scopes' => mb_substr((string) config('services.shopify.scopes', \App\Services\Shopify\ShopifyOAuth::DEFAULT_SCOPES), 0, 255),
                'requested_scopes' => (string) config('services.shopify.scopes', \App\Services\Shopify\ShopifyOAuth::DEFAULT_SCOPES),
                'api_version' => config('services.shopify.api_version', \App\Services\Shopify\ShopifyOAuth::API_VERSION),
            ]
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }
}
