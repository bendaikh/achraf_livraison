<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyShop extends Model
{
    protected $fillable = [
        'company_id',
        'catalog_synced_at',
        'catalog_sync_error',
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
            'catalog_synced_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Company owning this store (falls back to the default company for legacy rows). */
    public function resolveCompanyId(): int
    {
        if (! $this->company_id) {
            $this->forceFill(['company_id' => Company::default()->id])->save();
        }

        return (int) $this->company_id;
    }

    public function hasScope(string $scope): bool
    {
        $scopes = array_map('trim', explode(',', (string) $this->scopes));
        // write_x implies read_x
        return in_array($scope, $scopes, true) || in_array(str_replace('read_', 'write_', $scope), $scopes, true);
    }

    public function isInstalled(): bool
    {
        return $this->is_active && filled($this->access_token) && $this->uninstalled_at === null;
    }
}
