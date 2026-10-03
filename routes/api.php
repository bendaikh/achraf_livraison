<?php

use App\Http\Controllers\Api\CentreController;
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
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SpeedafIntegrationController;
use App\Http\Controllers\Api\SpeedafOrderController;
use Illuminate\Support\Facades\Route;

/*
| JSON API consumed by the React SPA (admin screens). Session auth (same as the SPA) and
| admin role required; the driver space uses /api/driver/* (routes/web.php).
*/
Route::middleware(['web', 'auth', 'admin.access'])->group(function () {
    Route::get('meta', [MetaController::class, 'show']);
    Route::get('dashboard', [DashboardController::class, 'show']);
    Route::get('centre', [CentreController::class, 'show']);

    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update']);

    // Paramètres → Statuts de livraison
    Route::get('delivery-statuses', [DeliveryStatusController::class, 'index']);
    Route::post('delivery-statuses', [DeliveryStatusController::class, 'store']);
    Route::put('delivery-statuses/{deliveryStatus}', [DeliveryStatusController::class, 'update']);
    Route::delete('delivery-statuses/{deliveryStatus}', [DeliveryStatusController::class, 'destroy']);
    Route::put('delivery-statuses/{deliveryStatus}/transitions', [DeliveryStatusController::class, 'updateTransitions']);
    Route::get('status-transitions', [DeliveryStatusController::class, 'transitions']);

    // Paramètres → Partenaires logistiques (scoped to the user's company)
    Route::get('logistics-partners', [LogisticsPartnerController::class, 'index']);
    Route::post('logistics-partners', [LogisticsPartnerController::class, 'store']);
    Route::put('logistics-partners/{logisticsPartner}', [LogisticsPartnerController::class, 'update']);
    Route::delete('logistics-partners/{logisticsPartner}', [LogisticsPartnerController::class, 'destroy']);
    Route::post('logistics-partners/{logisticsPartner}/deactivate', [LogisticsPartnerController::class, 'deactivate']);
    Route::post('logistics-partners/{logisticsPartner}/activate', [LogisticsPartnerController::class, 'activate']);
    Route::post('logistics-partners/{logisticsPartner}/favorite', [LogisticsPartnerController::class, 'favorite']);

    // Commandes → Affecter à livraison locale (bulk + fiche commande)
    Route::get('local-delivery/drivers', [LocalAssignmentController::class, 'drivers']);
    Route::post('local-delivery/assign', [LocalAssignmentController::class, 'assign'])->middleware('can:orders.assign_driver');

    Route::get('orders', [OrderController::class, 'index']);
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
    Route::post('drivers', [DriverController::class, 'store']);
    Route::get('drivers/{driver}', [DriverController::class, 'show']);
    Route::put('drivers/{driver}', [DriverController::class, 'update']);

    Route::get('missions', [MissionController::class, 'index']);
    Route::post('missions', [MissionController::class, 'store']);
    Route::get('missions/{mission}', [MissionController::class, 'show']);
    Route::put('missions/{mission}', [MissionController::class, 'update']);
    Route::post('missions/{mission}/status', [MissionController::class, 'changeStatus']);

    // Per-user UI preferences (current user = auth user, else user #1).
    Route::get('preferences/{key}', [PreferenceController::class, 'show']);
    Route::put('preferences/{key}', [PreferenceController::class, 'update']);

    // Intégrations → Speedaf (settings of the user's company) + Commandes actions
    Route::get('integrations/speedaf', [SpeedafIntegrationController::class, 'show']);
    Route::put('integrations/speedaf', [SpeedafIntegrationController::class, 'update']);
    Route::post('integrations/speedaf/test', [SpeedafIntegrationController::class, 'test']);
    Route::post('integrations/speedaf/webhook/subscribe', [SpeedafIntegrationController::class, 'subscribeWebhook']);
    Route::post('integrations/speedaf/sync', [SpeedafIntegrationController::class, 'sync']);
    Route::post('speedaf/orders/send', [SpeedafOrderController::class, 'send']);
    Route::post('speedaf/labels', [SpeedafOrderController::class, 'labels']);
    Route::post('speedaf/orders/{order}/cancel', [SpeedafOrderController::class, 'cancel']);
    Route::post('speedaf/orders/{order}/sync', [SpeedafOrderController::class, 'sync']);
    Route::get('speedaf/orders/{order}/label', [SpeedafOrderController::class, 'label']);

    // Clôture du jour (caisse livreurs)
    Route::get('closings', [ClosingController::class, 'index']);
    Route::get('closings/pending', [ClosingController::class, 'pending']);
    Route::post('closings', [ClosingController::class, 'store']);
});
