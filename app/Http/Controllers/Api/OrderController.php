<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\ConfirmationStatus;
use App\Models\DeliveryStatus;
use App\Models\Order;
use App\Services\OrderWorkflow;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Commandes (admin). Lists Shopify-synced and manual orders with the configurable
 * delivery / confirmation statuses.
 */
class OrderController extends Controller
{
    public function __construct(protected OrderWorkflow $workflow) {}

    public function index(Request $request)
    {
        $q = Order::query()->with(['deliveryStatus', 'driver', 'assignedUser', 'shop:id,shop_domain,shop_name', 'speedafShipments']);

        $search = trim((string) ($request->query('q') ?? $request->query('search', '')));
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('shipping_address->city', 'like', "%{$search}%")
                    ->orWhere('line_items', 'like', "%{$search}%");
            });
        }
        if ($request->filled('delivery_status_id')) {
            $code = DeliveryStatus::query()->whereKey($request->integer('delivery_status_id'))->value('code');
            $q->where('delivery_status', $code ?? '__none__');
        }
        if ($request->filled('delivery_status')) {
            $q->where('delivery_status', $request->query('delivery_status'));
        }
        if ($request->filled('status_category')) {
            $q->inDeliveryCategories(explode(',', $request->query('status_category')));
        }
        if ($request->filled('confirmation_status')) {
            $q->where('confirmation_status', $request->query('confirmation_status'));
        }
        if ($request->filled('driver_id')) {
            $request->query('driver_id') === 'none'
                ? $q->whereNull('driver_id')
                : $q->where('driver_id', $request->integer('driver_id'));
        }
        if ($request->filled('speedaf')) {
            $active = fn ($w) => $w->where('state', '!=', \App\Models\SpeedafShipment::STATE_CANCELLED);
            $request->query('speedaf') === '1'
                ? $q->whereHas('speedafShipments', $active)
                : $q->whereDoesntHave('speedafShipments', $active);
        }
        if ($request->filled('date_from')) {
            $q->where('created_at', '>=', Carbon::parse($request->query('date_from'))->startOfDay());
        }
        if ($request->filled('date_to')) {
            $q->where('created_at', '<=', Carbon::parse($request->query('date_to'))->endOfDay());
        }

        $perPage = min(max($request->integer('per_page', 25), 5), 100);

        return OrderResource::collection($q->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage));
    }

    public function show(Order $order)
    {
        return new OrderResource($order->load($this->detailRelations()));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $driverId = $data['driver_id'] ?? null;
        $confirmation = $data['confirmation_status'] ?? null;
        unset($data['driver_id'], $data['confirmation_status']);

        $order = Order::create(Order::attributesFromForm($data) + [
            'confirmation_status' => ConfirmationStatus::defaultCode(),
            'currency' => 'MAD',
            'source' => $data['source'] ?? 'Manuel',
        ]);
        $order->appendHistory('received', "Commande créée dans Lav'Fast Flow", $request->user());
        $order->save();

        if ($confirmation && $confirmation !== $order->confirmation_status) {
            $this->workflow->changeConfirmation($order, $confirmation, $request->user());
        }
        if ($driverId && $request->user()?->can('orders.assign_driver')) {
            $this->workflow->assignDriver($order, $driverId, $request->user());
        }

        return (new OrderResource($order->fresh()->load($this->detailRelations())))->response()->setStatusCode(201);
    }

    public function update(Request $request, Order $order)
    {
        $data = $this->validated($request, true);
        $hasDriver = array_key_exists('driver_id', $data);
        $driverId = $data['driver_id'] ?? null;
        unset($data['driver_id'], $data['confirmation_status']);

        if ($hasDriver && (int) $driverId !== (int) $order->driver_id && ! $request->user()?->can('orders.assign_driver')) {
            abort(403, 'Vous n’avez pas le droit d’affecter des commandes à un livreur.');
        }
        $order->fill(Order::attributesFromForm($data, $order))->save();
        if ($hasDriver && (int) $driverId !== (int) $order->driver_id) {
            $this->workflow->assignDriver($order, $driverId, $request->user());
        }

        return new OrderResource($order->fresh()->load($this->detailRelations()));
    }

    public function changeConfirmation(Request $request, Order $order)
    {
        $data = $request->validate([
            'confirmation_status' => ['required', 'string', Rule::exists('confirmation_statuses', 'code')->where('is_active', true)],
            'reason' => ['nullable', 'string', 'max:500'],
            'recall_at' => ['nullable', 'date'],
        ]);
        $this->workflow->changeConfirmation($order, $data['confirmation_status'], $request->user(), $data);

        return new OrderResource($order->fresh()->load($this->detailRelations()));
    }

    public function changeStatus(Request $request, Order $order)
    {
        $data = $request->validate([
            'delivery_status_id' => ['required', 'integer', 'exists:delivery_statuses,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'postponed_date' => ['nullable', 'date'],
            'postponed_time' => ['nullable', 'date_format:H:i'],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $status = DeliveryStatus::findOrFail($data['delivery_status_id']);
        if (! empty($data['postponed_date'])) {
            if (in_array('postponed_at', $status->requiredFields(), true) && empty($data['postponed_time'])) {
                return response()->json([
                    'message' => "L'heure de report est obligatoire.",
                    'errors' => ['postponed_time' => ["L'heure de report est obligatoire."]],
                ], 422);
            }
            $data['postponed_at'] = Carbon::parse($data['postponed_date'].' '.($data['postponed_time'] ?? '09:00'))->format('Y-m-d H:i');
        }
        $this->workflow->changeStatus($order, $status, $data, $request->user());

        return new OrderResource($order->fresh()->load($this->detailRelations()));
    }

    protected function detailRelations(): array
    {
        return ['deliveryStatus', 'driver', 'assignedUser', 'assignedByUser:id,name', 'shop:id,shop_domain,shop_name', 'missions.driver', 'histories.user', 'speedafShipments'];
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'customer_name' => [$req, 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_image' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'amount' => [$req, 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(array_keys(Catalog::PAYMENT_METHODS))],
            'confirmation_status' => ['nullable', 'string', Rule::exists('confirmation_statuses', 'code')->where('is_active', true)],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'carrier' => ['nullable', 'string', 'max:60'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'source' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
