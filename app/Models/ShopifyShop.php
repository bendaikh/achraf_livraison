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
        'granted_scopes',
        'orders_reconciled_at',
        'inventory_location_id',
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
            'orders_reconciled_at' => 'datetime',
        ];
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Scopes actually granted (full list; `scopes` stays within the legacy varchar). */
    public function grantedScopeList(): string
    {
        return (string) ($this->granted_scopes ?: $this->scopes);
    }

    public function rememberScopes(?string $list): void
    {
        $list = trim((string) $list);
        $this->granted_scopes = $list !== '' ? $list : null;
        $this->scopes = $list === '' ? null : mb_substr($list, 0, 255);
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
        $scopes = array_map('trim', explode(',', $this->grantedScopeList()));
        // write_x implies read_x
        return in_array($scope, $scopes, true) || in_array(str_replace('read_', 'write_', $scope), $scopes, true);
    }

    public function isInstalled(): bool
    {
        return $this->is_active && filled($this->access_token) && $this->uninstalled_at === null;
    }

    /**
     * What this shop can do right now. inventory_write also needs the company
     * feature shopify_push_stock and a configured location.
     *
     * @return array{orders_read:bool,orders_write:bool,orders_create:bool,orders_cancel:bool,order_edit:bool,customers_write:bool,products_write:bool,inventory_write:bool,fulfillments_write:bool}
     */
    public function capabilities(): array
    {
        $company = $this->company_id ? Company::query()->find($this->company_id) : null;
        $stock = ($company?->hasFeature('shopify_push_stock', false) ?? false) && filled($this->inventory_location_id);
        $writeOrders = $this->hasScope('write_orders');

        return [
            'orders_read' => $this->hasScope('read_orders'),
            'orders_write' => $writeOrders,
            'orders_create' => $writeOrders,
            'orders_cancel' => $writeOrders,
            'order_edit' => $this->hasScope('write_order_edits'),
            'customers_write' => $this->hasScope('write_customers'),
            'products_write' => $this->hasScope('write_products'),
            'inventory_write' => $this->hasScope('write_inventory') && $stock,
            'fulfillments_write' => $this->hasScope('write_merchant_managed_fulfillment_orders'),
        ];
    }

    /** Scopes still required for two-way sync. */
    public function missingScopes(): array
    {
        $required = array_values(array_filter(array_map('trim', explode(',', \App\Services\Shopify\ShopifyOAuth::DEFAULT_SCOPES))));
        $missing = [];
        foreach ($required as $scope) {
            if (! $this->hasScope($scope)) {
                $missing[] = $scope;
            }
        }

        return $missing;
    }
}
