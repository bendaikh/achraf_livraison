<?php

use App\Http\Controllers\Api\ClosingController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryStatusController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\MissionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

/*
| JSON API consumed by the React SPA (admin screens). Session auth (same as the SPA) and
| admin role required; the driver space uses /api/driver/* (routes/web.php).
*/
Route::middleware(['web', 'auth', 'admin.access'])->group(function () {
    Route::get('meta', [MetaController::class, 'show']);
    Route::get('dashboard', [DashboardController::class, 'show']);

    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update']);

    // Paramètres → Statuts de livraison
    Route::get('delivery-statuses', [DeliveryStatusController::class, 'index']);
    Route::post('delivery-statuses', [DeliveryStatusController::class, 'store']);
    Route::put('delivery-statuses/{deliveryStatus}', [DeliveryStatusController::class, 'update']);
    Route::delete('delivery-statuses/{deliveryStatus}', [DeliveryStatusController::class, 'destroy']);
    Route::put('delivery-statuses/{deliveryStatus}/transitions', [DeliveryStatusController::class, 'updateTransitions']);
    Route::get('status-transitions', [DeliveryStatusController::class, 'transitions']);

    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::put('orders/{order}', [OrderController::class, 'update']);
    Route::post('orders/{order}/status', [OrderController::class, 'changeStatus']);
    Route::post('orders/{order}/confirmation', [OrderController::class, 'changeConfirmation']);

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

    // Clôture du jour (caisse livreurs)
    Route::get('closings', [ClosingController::class, 'index']);
    Route::get('closings/pending', [ClosingController::class, 'pending']);
    Route::post('closings', [ClosingController::class, 'store']);
});
