<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderWorkflow;
use App\Support\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentController extends Controller
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $search = trim((string) $request->query('search', ''));
        $city = trim((string) $request->query('city', ''));
        $filter = trim((string) $request->query('filter', 'to_assign'));

        $query = Order::query()
            ->with(['shop:id,shop_domain,shop_name', 'driver:id,name,phone', 'deliveryStatus'])
            ->latest('confirmed_at')
            ->latest('shopify_created_at');

        $this->applyFilter($query, $filter);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($city !== '') {
            $query->where('shipping_address->city', 'like', "%{$city}%");
        }

        $paginator = $query->paginate($perPage);
        $orders = collect($paginator->items())->map(fn (Order $order) => $this->listPayload($order));

        $countBase = Order::query()->confirmed();
        if ($search !== '') {
            $countBase->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }
        if ($city !== '') {
            $countBase->where('shipping_address->city', 'like', "%{$city}%");
        }

        return response()->json([
            'orders' => $orders,
            'counts' => [
                'to_assign' => (clone $countBase)->awaitingAssignment()->count(),
                'assigned' => (clone $countBase)->activeWithDriver()->count(),
                'delivered' => (clone $countBase)->inDeliveryCategories(['succes'])->count(),
                'failed' => (clone $countBase)->inDeliveryCategories(Catalog::RETRY_CATEGORIES)->count(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'filter' => $filter,
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load([
            'shop:id,shop_domain,shop_name',
            'driver:id,name,phone',
            'assignedByUser:id,name',
            'confirmedByUser:id,name',
        ]);

        return response()->json([
            'order' => $this->detailPayload($order),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'distinct', 'exists:orders,id'],
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
        ], [
            'order_ids.required' => 'Sélectionnez au moins une commande.',
            'driver_id.required' => 'Sélectionnez un livreur.',
            'driver_id.exists' => 'Livreur introuvable.',
        ]);

        /** @var User $user */
        $user = $request->user();

        $driver = Driver::query()->findOrFail($data['driver_id']);
        if (! $driver->is_active) {
            throw ValidationException::withMessages([
                'driver_id' => 'Ce livreur est inactif.',
            ]);
        }

        $orders = Order::query()
            ->whereIn('id', $data['order_ids'])
            ->get();

        if ($orders->count() !== count($data['order_ids'])) {
            throw ValidationException::withMessages([
                'order_ids' => 'Une ou plusieurs commandes sont introuvables.',
            ]);
        }

        $notAssignable = $orders->filter(fn (Order $order) => ! $order->canBeAssigned());
        if ($notAssignable->isNotEmpty()) {
            $names = $notAssignable->map(fn (Order $o) => $o->name ?: '#'.$o->id)->implode(', ');
            throw ValidationException::withMessages([
                'order_ids' => 'Ces commandes ne peuvent pas être attribuées : '.$names,
            ]);
        }

        // Through the workflow: livraison mission with the driver's tariff snapshot,
        // configurable "Attribuée" status and status history.
        DB::transaction(function () use ($orders, $driver, $user) {
            foreach ($orders as $order) {
                $order->forceFill([
                    'amount_collected' => null,
                    'delivered_at' => null,
                    'cod_remitted_at' => null,
                ]);
                $this->workflow->assignDriver($order, $driver->id, $user);
            }
        });

        $count = $orders->count();

        return response()->json([
            'message' => $count === 1
                ? 'Commande affectée à '.$driver->name.'.'
                : $count.' commandes affectées à '.$driver->name.'.',
            'assigned_count' => $count,
            'driver' => [
                'id' => $driver->id,
                'name' => $driver->name,
            ],
        ]);
    }

    private function applyFilter($query, string $filter): void
    {
        $query->confirmed();

        match ($filter) {
            'assigned' => $query->activeWithDriver(),
            'delivered' => $query->inDeliveryCategories(['succes']),
            'failed' => $query->inDeliveryCategories(Catalog::RETRY_CATEGORIES),
            default => $query->awaitingAssignment(),
        };
    }

    private function listPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'name' => $order->name,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'phone' => $order->phone,
            'city' => $order->shippingCity(),
            'address' => $order->shippingAddressLine(),
            'total_price' => $order->total_price,
            'shipping_price' => $order->shipping_price,
            'currency' => $order->currency,
            'line_items' => $order->line_items ?? [],
            'note' => $order->note,
            'internal_note' => $order->internal_note,
            'confirmation_status' => $order->confirmation_status,
            'delivery_status' => $order->delivery_status,
            'delivery_status_label' => $order->deliveryStatusLabel(),
            'delivery_status_color' => $order->deliveryStatusColor(),
            'can_assign' => $order->canBeAssigned(),
            'driver_id' => $order->driver_id,
            'driver_name' => $order->driver?->name,
            'assigned_at' => $order->assigned_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'shopify_created_at' => $order->shopify_created_at?->toIso8601String(),
            'delivery_postponed_until' => $order->delivery_postponed_until?->toIso8601String(),
            'delivery_failure_reason' => $order->delivery_failure_reason,
            'amount_collected' => $order->amount_collected,
            'delivered_at' => $order->delivered_at?->toIso8601String(),
        ];
    }

    private function detailPayload(Order $order): array
    {
        return array_merge($this->listPayload($order), [
            'email' => $order->email,
            'shipping_address' => $order->shipping_address,
            'confirmation_history' => $order->confirmation_history ?? [],
            'assigned_by' => $order->assigned_by,
            'assigned_by_name' => $order->assignedByUser?->name,
            'confirmed_by_name' => $order->confirmedByUser?->name,
            'shop_name' => $order->shop?->shop_name,
        ]);
    }
}
