<?php

use App\Http\Controllers\Api\CarrierController;
use App\Http\Controllers\Api\CentreController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClosingController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryStatusController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\LocalAssignmentController;
use App\Http\Controllers\Api\LogisticsPartnerController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\MissionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderItemController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SavController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SpeedafIntegrationController;
use App\Http\Controllers\Api\SpeedafOrderController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| JSON API consumed by the React SPA (admin screens). Session auth (same as the SPA) and
| admin role required; the driver space uses /api/driver/* (routes/web.php).
*/
Route::middleware(['web', 'auth', 'admin.access'])->group(function () {
    Route::get('meta', [MetaController::class, 'show']);

    // T7 — Retours / échanges (SAV)
    Route::middleware('can:sav.manage')->group(function () {
        Route::get('sav', [SavController::class, 'index']);
        Route::get('sav/meta', [SavController::class, 'meta']);
        Route::get('sav/custody', [SavController::class, 'custody']);
        Route::get('sav/orders', [SavController::class, 'searchOrders']);
        Route::get('sav/orders/{order}/prefill', [SavController::class, 'prefill']);
        Route::post('sav', [SavController::class, 'store']);
        Route::get('sav/{sav}', [SavController::class, 'show']);
        Route::post('sav/{sav}/assign', [SavController::class, 'assign']);
        Route::post('sav/{sav}/action', [SavController::class, 'action']);
        Route::get('orders/{order}/sav', [SavController::class, 'forOrder']);
    });

    // T9 — Clients
    Route::middleware('can:clients.view')->group(function () {
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('clients/summary', [ClientController::class, 'summary']);
        Route::get('clients/{key}', [ClientController::class, 'show'])->where('key', '[0-9]+');
        Route::post('clients/{key}/notes', [ClientController::class, 'addNote'])->where('key', '[0-9]+');
    });
    Route::middleware('can:clients.block')->group(function () {
        Route::post('clients/{key}/block', [ClientController::class, 'block'])->where('key', '[0-9]+');
        Route::post('clients/{key}/unblock', [ClientController::class, 'unblock'])->where('key', '[0-9]+');
    });
    Route::middleware('can:clients.groups')->group(function () {
        Route::post('client-groups', [ClientController::class, 'storeGroup']);
        Route::put('client-groups/{group}', [ClientController::class, 'updateGroup']);
        Route::delete('client-groups/{group}', [ClientController::class, 'destroyGroup']);
        Route::post('client-groups/{group}/members', [ClientController::class, 'addMembers']);
        Route::delete('client-groups/{group}/members/{key}', [ClientController::class, 'removeMember']);
    });
    Route::get('dashboard', [DashboardController::class, 'show'])->middleware('can:dashboard.view');
    Route::get('centre', [CentreController::class, 'show'])->middleware('can:dashboard.view');

    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update'])->middleware('can:settings.manage');

    // Paramètres → Statuts de livraison
    Route::get('delivery-statuses', [DeliveryStatusController::class, 'index']);
    Route::post('delivery-statuses', [DeliveryStatusController::class, 'store'])->middleware('can:settings.manage');
    Route::put('delivery-statuses/{deliveryStatus}', [DeliveryStatusController::class, 'update'])->middleware('can:settings.manage');
    Route::delete('delivery-statuses/{deliveryStatus}', [DeliveryStatusController::class, 'destroy'])->middleware('can:settings.manage');
    Route::put('delivery-statuses/{deliveryStatus}/transitions', [DeliveryStatusController::class, 'updateTransitions'])->middleware('can:settings.manage');
    Route::get('status-transitions', [DeliveryStatusController::class, 'transitions']);

    // Paramètres → Partenaires logistiques (scoped to the user's company)
    Route::get('logistics-partners', [LogisticsPartnerController::class, 'index']);
    Route::post('logistics-partners', [LogisticsPartnerController::class, 'store'])->middleware('can:settings.manage');
    Route::put('logistics-partners/{logisticsPartner}', [LogisticsPartnerController::class, 'update'])->middleware('can:settings.manage');
    Route::delete('logistics-partners/{logisticsPartner}', [LogisticsPartnerController::class, 'destroy'])->middleware('can:settings.manage');
    Route::post('logistics-partners/{logisticsPartner}/deactivate', [LogisticsPartnerController::class, 'deactivate'])->middleware('can:settings.manage');
    Route::post('logistics-partners/{logisticsPartner}/activate', [LogisticsPartnerController::class, 'activate'])->middleware('can:settings.manage');
    Route::post('logistics-partners/{logisticsPartner}/favorite', [LogisticsPartnerController::class, 'favorite'])->middleware('can:settings.manage');

    // Commandes → Affecter à livraison locale (bulk + fiche commande)
    Route::get('local-delivery/drivers', [LocalAssignmentController::class, 'drivers']);
    Route::post('local-delivery/assign', [LocalAssignmentController::class, 'assign'])->middleware('can:orders.assign_driver');

    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/kanban', [OrderController::class, 'kanban']);
    Route::post('orders/bulk-status', [OrderController::class, 'bulkStatus']);
    Route::post('orders/assign-agent', [OrderController::class, 'assignAgent'])->middleware('can:orders.assign_agent');
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::put('orders/{order}', [OrderController::class, 'update']);
    Route::post('orders/{order}/status', [OrderController::class, 'changeStatus']);
    Route::post('orders/{order}/confirmation', [OrderController::class, 'changeConfirmation']);

    // Lignes de commande (modification interne, jamais poussée vers Shopify)
    Route::middleware('can:orders.edit_items')->group(function () {
        Route::post('orders/{order}/items', [OrderItemController::class, 'store']);
        Route::put('orders/{order}/items/{key}', [OrderItemController::class, 'update']);
        Route::delete('orders/{order}/items/{key}', [OrderItemController::class, 'destroy']);
        Route::post('orders/{order}/items/{key}/replace', [OrderItemController::class, 'replace']);
    });

    // Produits (catalogue Shopify synchronisé)
    Route::get('products', [ProductController::class, 'index'])->middleware('can:products.view');
    Route::get('products/status', [ProductController::class, 'status'])->middleware('can:products.view');
    Route::post('products/sync', [ProductController::class, 'sync'])->middleware('can:products.sync');
    Route::post('products/webhooks', [ProductController::class, 'registerWebhooks'])->middleware('can:products.sync');

    Route::get('drivers', [DriverController::class, 'index']);
    Route::get('drivers/active', [DriverController::class, 'active']);
    Route::post('drivers', [DriverController::class, 'store'])->middleware('can:drivers.manage');
    Route::get('drivers/{driver}', [DriverController::class, 'show']);
    Route::put('drivers/{driver}', [DriverController::class, 'update'])->middleware('can:drivers.manage');

    Route::get('missions', [MissionController::class, 'index']);
    Route::post('missions', [MissionController::class, 'store'])->middleware('can:drivers.manage');
    Route::get('missions/{mission}', [MissionController::class, 'show']);
    Route::put('missions/{mission}', [MissionController::class, 'update'])->middleware('can:drivers.manage');
    Route::post('missions/{mission}/status', [MissionController::class, 'changeStatus'])->middleware('can:drivers.manage');

    // Per-user UI preferences (current user = auth user, else user #1).
    Route::get('preferences/{key}', [PreferenceController::class, 'show']);
    Route::put('preferences/{key}', [PreferenceController::class, 'update']);

    // Intégrations → Speedaf (settings of the user's company) + Commandes actions
    Route::get('integrations/speedaf', [SpeedafIntegrationController::class, 'show'])->middleware('can:settings.manage');
    Route::put('integrations/speedaf', [SpeedafIntegrationController::class, 'update'])->middleware('can:settings.manage');
    Route::post('integrations/speedaf/test', [SpeedafIntegrationController::class, 'test'])->middleware('can:settings.manage');
    Route::post('integrations/speedaf/webhook/subscribe', [SpeedafIntegrationController::class, 'subscribeWebhook'])->middleware('can:settings.manage');
    Route::post('integrations/speedaf/sync', [SpeedafIntegrationController::class, 'sync'])->middleware('can:settings.manage');
    Route::get('carriers', [CarrierController::class, 'index']);
    Route::post('carriers/labels', [CarrierController::class, 'labels']);
    Route::post('carriers/{carrier}/ship', [CarrierController::class, 'ship'])->middleware('can:orders.ship')->where('carrier', '[a-z0-9_-]+');
    Route::post('speedaf/orders/send', [SpeedafOrderController::class, 'send'])->middleware('can:orders.ship');
    Route::post('speedaf/labels', [SpeedafOrderController::class, 'labels']);
    Route::post('speedaf/orders/{order}/cancel', [SpeedafOrderController::class, 'cancel'])->middleware('can:orders.ship');
    Route::post('speedaf/orders/{order}/sync', [SpeedafOrderController::class, 'sync']);
    Route::get('speedaf/orders/{order}/label', [SpeedafOrderController::class, 'label']);

    // Clôture du jour (caisse livreurs)
    Route::middleware('can:closings.manage')->group(function () {
        Route::get('closings', [ClosingController::class, 'index']);
        Route::get('closings/pending', [ClosingController::class, 'pending']);
        Route::post('closings', [ClosingController::class, 'store']);
    });

    // Équipe (T6): utilisateurs, services, performance, commissions
    Route::middleware('can:users.manage')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::get('services', [ServiceController::class, 'index']);
        Route::post('services', [ServiceController::class, 'store']);
        Route::put('services/{service}', [ServiceController::class, 'update']);
        Route::delete('services/{service}', [ServiceController::class, 'destroy']);
    });
    Route::get('team/performance', [TeamController::class, 'performance']);
    Route::get('team/commissions', [TeamController::class, 'commissions']);
    Route::post('team/commissions/transition', [TeamController::class, 'transition'])->middleware('can:commissions.manage');
    Route::post('team/commissions/monthly', [TeamController::class, 'monthly'])->middleware('can:commissions.manage');
});
