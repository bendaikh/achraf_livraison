<?php

use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\MissionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

/*
| JSON API consumed by the React SPA. No authentication yet (see CurrentUser).
*/
Route::get('meta', [MetaController::class, 'show']);

Route::get('settings', [SettingsController::class, 'show']);
Route::put('settings', [SettingsController::class, 'update']);

Route::get('orders', [OrderController::class, 'index']);
Route::post('orders', [OrderController::class, 'store']);
Route::get('orders/{order}', [OrderController::class, 'show']);
Route::put('orders/{order}', [OrderController::class, 'update']);
Route::post('orders/{order}/status', [OrderController::class, 'changeStatus']);
Route::post('orders/{order}/confirmation', [OrderController::class, 'changeConfirmation']);

Route::get('drivers', [DriverController::class, 'index']);
Route::post('drivers', [DriverController::class, 'store']);
Route::get('drivers/{driver}', [DriverController::class, 'show']);
Route::put('drivers/{driver}', [DriverController::class, 'update']);

Route::get('missions', [MissionController::class, 'index']);
Route::post('missions', [MissionController::class, 'store']);
Route::get('missions/{mission}', [MissionController::class, 'show']);
Route::post('missions/{mission}/status', [MissionController::class, 'changeStatus']);
