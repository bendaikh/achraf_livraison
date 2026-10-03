<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Team\CommissionService;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Keeps agent commissions in sync with order events (confirmation, shipping, delivery, return…). */
class OrderCommissionObserver
{
    protected const WATCHED = ['confirmation_status', 'confirmed_by', 'assigned_user_id', 'delivery_status', 'driver_id', 'carrier', 'closing_id', 'cod_remitted_at'];

    public function saved(Order $order): void
    {
        if (! $order->wasRecentlyCreated && ! $order->wasChanged(self::WATCHED)) {
            return;
        }
        try {
            app(CommissionService::class)->syncOrder($order);
        } catch (Throwable $e) {
            Log::warning('Commission sync failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
