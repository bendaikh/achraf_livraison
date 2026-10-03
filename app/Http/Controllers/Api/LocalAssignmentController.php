<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Services\OrderWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Commandes → « Affecter à livraison locale » (bulk selection bar + single order page).
 * Each order is moved to the chosen driver through OrderWorkflow::assignDriver (status
 * « Attribuée » or its configured equivalent, mission + tariff snapshot, history). Orders that
 * cannot be assigned are reported one by one, the others are still assigned.
 */
class LocalAssignmentController extends Controller
{
    public function __construct(protected OrderWorkflow $workflow) {}

    /** Active drivers with their current load for the « Choisir un livreur » drawer. */
    public function drivers(): JsonResponse
    {
        $drivers = Driver::query()->active()->orderBy('name')->get()->map(fn (Driver $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'phone' => $d->phone,
            'city' => $d->city,
            'is_active' => (bool) $d->is_active,
            'missions_in_progress' => $d->assignedOrdersCount(),
            'cod_held' => round($d->codHeldAmount(), 2),
        ]);

        return response()->json(['drivers' => $drivers]);
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:500'],
            'order_ids.*' => ['integer', 'distinct'],
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
        ], [
            'order_ids.required' => 'Sélectionnez au moins une commande.',
            'driver_id.required' => 'Choisissez un livreur.',
            'driver_id.exists' => 'Livreur introuvable.',
        ]);

        $driver = Driver::query()->findOrFail($data['driver_id']);
        if (! $driver->is_active) {
            throw ValidationException::withMessages(['driver_id' => 'Ce livreur est inactif.']);
        }

        $orders = Order::query()->with(['deliveryStatus', 'speedafShipments', 'driver'])->whereIn('id', $data['order_ids'])->get()->keyBy('id');
        $results = [];
        $assigned = 0;
        foreach ($data['order_ids'] as $id) {
            /** @var Order|null $order */
            $order = $orders->get($id);
            if (! $order) {
                $results[] = ['order_id' => $id, 'reference' => '#'.$id, 'success' => false, 'message' => 'Commande introuvable.'];

                continue;
            }
            $blocker = $order->localAssignmentBlocker($driver->id);
            if ($blocker) {
                $results[] = ['order_id' => $id, 'reference' => $order->reference(), 'success' => false, 'message' => $blocker];

                continue;
            }
            $from = $order->driver?->name;
            $order->forceFill(['amount_collected' => null, 'delivered_at' => null, 'cod_remitted_at' => null]);
            $this->workflow->assignDriver($order, $driver->id, $request->user());
            $assigned++;
            $results[] = [
                'order_id' => $id,
                'reference' => $order->reference(),
                'success' => true,
                'message' => $from && $from !== $driver->name ? "Réaffectée : {$from} → {$driver->name}" : "Affectée à {$driver->name}",
                'status' => $order->fresh()->deliveryStatusLabel(),
            ];
        }

        $failed = count($results) - $assigned;
        $message = $assigned === 0
            ? 'Aucune commande affectée.'
            : ($assigned === 1 ? '1 commande affectée à '.$driver->name.'.' : $assigned.' commandes affectées à '.$driver->name.'.');
        if ($failed > 0) {
            $message .= ' '.$failed.' non affectée(s).';
        }

        return response()->json([
            'message' => $message,
            'assigned_count' => $assigned,
            'failed_count' => $failed,
            'driver' => ['id' => $driver->id, 'name' => $driver->name],
            'results' => $results,
        ], $assigned === 0 ? 422 : 200);
    }
}
