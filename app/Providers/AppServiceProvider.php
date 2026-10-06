<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use App\Observers\OrderAutomationObserver;
use App\Observers\OrderCommissionObserver;
use App\Services\Automations\AutomationRegistry;
use App\Services\Automations\Bootstrap\RegisterBuiltinAutomations;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Catalog\CatalogLookup;
use App\Services\Team\CommissionService;
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
        $this->app->scoped(CatalogLookup::class);
        $this->app->singleton(CarrierRegistry::class);
        $this->app->singleton(AutomationRegistry::class);
        $this->app->scoped(CommissionService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Order::observe(OrderCommissionObserver::class);
        Order::observe(OrderAutomationObserver::class);

        // Built-in + future integrations register triggers/actions on the shared registry.
        (new RegisterBuiltinAutomations)($this->app->make(AutomationRegistry::class));

        // Role-based abilities (config/permissions.php); unknown abilities fall through to normal gates.
        Gate::before(function (User $user, string $ability) {
            return Permissions::isKnown($ability) ? Permissions::allows($user, $ability) : null;
        });
    }
}
