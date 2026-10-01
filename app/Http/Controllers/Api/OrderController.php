<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\DeliveryStatus;
use App\Models\Order;
use App\Services\OrderWorkflow;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(protected OrderWorkflow $workflow) {}

    public function index(Request $request)
    {
        $q = Order::query()->with(['deliveryStatus', 'driver', 'assignedUser']);

        if ($search = trim((string) $request->query('q'))) {
            $q->where(function ($w) use ($search) {
                $w->where('reference', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('product_name', 'like', "%{$search}%");
            });
        }
        if ($request->filled('delivery_status_id')) {
            $q->where('delivery_status_id', $request->integer('delivery_status_id'));
        }
        if ($request->filled('status_category')) {
            $q->whereIn('delivery_status_id', DeliveryStatus::idsForCategories(explode(',', $request->query('status_category'))));
        }
        if ($request->filled('confirmation_status')) {
            $q->where('confirmation_status', $request->query('confirmation_status'));
        }
        if ($request->filled('driver_id')) {
            $request->query('driver_id') === 'none'
                ? $q->whereNull('driver_id')
                : $q->where('driver_id', $request->integer('driver_id'));
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
        unset($data['driver_id']);

        $order = Order::create($data + ['confirmation_status' => 'a_confirmer']);
        if (($data['confirmation_status'] ?? 'a_confirmer') !== 'a_confirmer') {
            $this->workflow->changeConfirmation($order, $data['confirmation_status']);
        }
        if ($driverId) {
            $this->workflow->assignDriver($order, $driverId);
        }

        return (new OrderResource($order->fresh()->load($this->detailRelations())))->response()->setStatusCode(201);
    }

    public function update(Request $request, Order $order)
    {
        $data = $this->validated($request, true);
        $hasDriver = array_key_exists('driver_id', $data);
        $driverId = $data['driver_id'] ?? null;
        unset($data['driver_id'], $data['confirmation_status']);

        $order->fill($data)->save();
        if ($hasDriver && (int) $driverId !== (int) $order->driver_id) {
            $this->workflow->assignDriver($order, $driverId);
        }

        return new OrderResource($order->fresh()->load($this->detailRelations()));
    }

    public function changeConfirmation(Request $request, Order $order)
    {
        $data = $request->validate([
            'confirmation_status' => ['required', Rule::in(array_keys(Catalog::CONFIRMATION_STATUSES))],
        ]);
        $this->workflow->changeConfirmation($order, $data['confirmation_status']);

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
            $data['postponed_at'] = Carbon::parse($data['postponed_date'].' '.($data['postponed_time'] ?? '09:00'));
        }
        $this->workflow->changeStatus($order, $status, $data);

        return new OrderResource($order->fresh()->load($this->detailRelations()));
    }

    protected function detailRelations(): array
    {
        return ['deliveryStatus', 'driver', 'assignedUser', 'missions.driver'];
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'customer_name' => [$req, 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_image' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'amount' => [$req, 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(array_keys(Catalog::PAYMENT_METHODS))],
            'confirmation_status' => ['nullable', Rule::in(array_keys(Catalog::CONFIRMATION_STATUSES))],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'carrier' => ['nullable', 'string', 'max:60'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'source' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
