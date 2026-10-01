<?php

namespace App\Services;

use App\Models\DeliveryStatus;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class OrderWorkflow
{
    /** Status automatically applied when an order gets confirmed (configurable). */
    public function statusOnConfirm(): ?DeliveryStatus
    {
        $id = Setting::getValue('status_on_confirm_id');

        return ($id ? DeliveryStatus::find($id) : null)
            ?? DeliveryStatus::active()->where('category', 'avant_livraison')->ordered()->first();
    }

    /** Status automatically applied when a driver is assigned (configurable). */
    public function statusOnAssign(): ?DeliveryStatus
    {
        $id = Setting::getValue('status_on_assign_id');

        return $id ? DeliveryStatus::find($id) : null;
    }

    public function changeConfirmation(Order $order, string $confirmation): Order
    {
        return DB::transaction(function () use ($order, $confirmation) {
            $order->confirmation_status = $confirmation;
            $order->status_changed_at = now();
            if ($confirmation === 'confirmee') {
                $order->confirmed_at ??= now();
                if (! $order->delivery_status_id && ($initial = $this->statusOnConfirm())) {
                    $this->applyStatus($order, $initial, [], 'Confirmation de la commande');
                }
            }
            $order->save();

            return $order;
        });
    }

    public function assignDriver(Order $order, ?int $driverId): Order
    {
        return DB::transaction(function () use ($order, $driverId) {
            $order->driver_id = $driverId;
            $order->save();

            $current = $order->deliveryStatus;
            if ($driverId && (! $current || $current->category === 'avant_livraison')) {
                $assign = $this->statusOnAssign();
                if ($assign && $assign->id !== $order->delivery_status_id) {
                    $this->applyStatus($order, $assign, [], 'Attribution au livreur');
                    $order->save();
                }
            }

            return $order;
        });
    }

    public function changeStatus(Order $order, DeliveryStatus $status, array $data = []): Order
    {
        return DB::transaction(function () use ($order, $status, $data) {
            $this->applyStatus($order, $status, $data, $data['note'] ?? null);
            $order->save();

            return $order;
        });
    }

    protected function applyStatus(Order $order, DeliveryStatus $status, array $data, ?string $note): void
    {
        $order->delivery_status_id = $status->id;
        $order->setRelation('deliveryStatus', $status);
        $order->status_changed_at = now();

        if (array_key_exists('reason', $data)) {
            $order->status_reason = $data['reason'];
        }
        if (! empty($data['postponed_at'])) {
            $order->postponed_at = $data['postponed_at'];
        }
        if ($status->category === 'succes') {
            $order->delivered_at ??= now();
            if (array_key_exists('collected_amount', $data) && $data['collected_amount'] !== null) {
                $order->collected_amount = $data['collected_amount'];
            } elseif ($order->collected_amount === null) {
                $order->collected_amount = $order->payment_method === 'cod' ? $order->amount : 0;
            }
        }
    }
}
