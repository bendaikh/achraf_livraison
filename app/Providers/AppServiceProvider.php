<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One catalog cache per request (order lines ↔ synced Shopify products).
        $this->app->scoped(\App\Services\Catalog\CatalogLookup::class);
        $this->app->singleton(\App\Services\Carriers\CarrierRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Role-based abilities (config/permissions.php); unknown abilities fall through to normal gates.
        Gate::before(function (User $user, string $ability) {
            return Permissions::isKnown($ability) ? Permissions::allows($user, $ability) : null;
        });
    }
}
