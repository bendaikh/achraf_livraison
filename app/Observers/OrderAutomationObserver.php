<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Automations\AutomationDispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes order domain changes to the automation engine (no hardcoded scenarios).
 */
class OrderAutomationObserver
{
    public function created(Order $order): void
    {
        $this->safe(fn (AutomationDispatcher $d) => $d->orderCreated($order));
    }

    public function updated(Order $order): void
    {
        try {
            $this->apply($order);
        } finally {
            \App\Services\Shopify\SyncContext::clearSuppressed($order->id);
        }
    }

    protected function apply(Order $order): void
    {
        $this->safe(function (AutomationDispatcher $d) use ($order) {
            $changes = $order->getChanges();
            unset($changes['updated_at']);
            $suppress = \App\Services\Shopify\SyncContext::suppressedFor($order->id);
            if ($suppress === true) {
                return;
            }
            if (is_array($suppress)) {
                foreach ($suppress as $field) {
                    unset($changes[$field]);
                }
            }
            if ($changes === []) {
                return;
            }

            if (array_key_exists('delivery_status', $changes)) {
                $d->orderStatusChanged($order, $order->getOriginal('delivery_status'), $order->delivery_status);
            }
            if (array_key_exists('confirmation_status', $changes)) {
                $d->orderConfirmationChanged($order, $order->getOriginal('confirmation_status'), $order->confirmation_status);
            }
            if (array_key_exists('financial_status', $changes)) {
                $d->dispatch('order.payment_changed', $d->companyIdForOrder($order), $order, [
                    'order_id' => $order->id,
                    'from' => $order->getOriginal('financial_status'),
                    'to' => $order->financial_status,
                ], 'order.payment_changed:'.$order->id.':'.$order->financial_status);
            }
            if (array_key_exists('assigned_user_id', $changes)) {
                $d->dispatch('order.assigned_agent', $d->companyIdForOrder($order), $order, [
                    'order_id' => $order->id,
                    'user_id' => $order->assigned_user_id,
                ], 'order.assigned_agent:'.$order->id.':'.$order->assigned_user_id);
            }
            if (array_key_exists('driver_id', $changes)) {
                $d->dispatch('order.assigned_driver', $d->companyIdForOrder($order), $order, [
                    'order_id' => $order->id,
                    'driver_id' => $order->driver_id,
                ], 'order.assigned_driver:'.$order->id.':'.$order->driver_id);
            }
            if (array_key_exists('carrier', $changes)) {
                $d->dispatch('order.assigned_carrier', $d->companyIdForOrder($order), $order, [
                    'order_id' => $order->id,
                    'carrier' => $order->carrier,
                ], 'order.assigned_carrier:'.$order->id.':'.$order->carrier);
            }

            $d->orderUpdated($order, $changes);
        });
    }

    protected function safe(callable $fn): void
    {
        try {
            $fn(app(AutomationDispatcher::class));
        } catch (Throwable $e) {
            Log::warning('OrderAutomationObserver failed', ['error' => $e->getMessage()]);
        }
    }
}
