<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Models\SavRequest;
use App\Models\SavRequestItem;
use App\Services\Sav\SavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** T7 — Retours / échanges (back-office). */
class SavController extends Controller
{
    public function __construct(private SavService $sav) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', 'string'], 'type' => ['nullable', 'in:retour,echange'], 'driver_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'], 'order_id' => ['nullable', 'integer'],
        ]);
        $base = SavRequest::query()
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($f['driver_id'] ?? null, fn ($q, $d) => $q->where('driver_id', $d))
            ->when($f['order_id'] ?? null, fn ($q, $o) => $q->where('order_id', $o))
            ->when($f['search'] ?? null, function ($q, $s) {
                $q->where(fn ($w) => $w->where('reference', 'like', "%$s%")->orWhere('customer_name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")
                    ->orWhereHas('order', fn ($o) => $o->where('name', 'like', "%$s%")->orWhere('order_number', 'like', "%$s%")));
            });
        $counts = (clone $base)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $status = $f['status'] ?? 'open';
        $list = (clone $base)
            ->when($status === 'open', fn ($q) => $q->whereNotIn('status', ['closed', 'cancelled']))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->latest('id')->limit(200)->get();

        return response()->json([
            'data' => $list->map(fn ($s) => $this->sav->payload($s))->values(),
            'counts' => $counts,
            'open' => (int) $counts->except(['closed', 'cancelled'])->sum(),
            'statuses' => collect(config('sav.statuses'))->map(fn ($s, $k) => ['value' => $k] + $s)->values(),
        ]);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'types' => collect(config('sav.types'))->map(fn ($t, $k) => ['value' => $k] + $t)->values(),
            'reasons' => config('sav.reasons'),
            'statuses' => collect(config('sav.statuses'))->map(fn ($s, $k) => ['value' => $k] + $s)->values(),
            'drivers' => Driver::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone', 'tariff_retour', 'tariff_echange']),
        ]);
    }

    /** Delivered orders search (number, client name, phone). */
    public function searchOrders(Request $request): JsonResponse
    {
        $s = trim((string) $request->query('q', ''));
        $q = Order::query()->inDeliveryCategories(['succes'])->latest('delivered_at')->latest('id');
        if ($s !== '') {
            $digits = preg_replace('/\D/', '', $s);
            $q->where(function ($w) use ($s, $digits) {
                $w->where('name', 'like', "%$s%")->orWhere('order_number', 'like', "%$s%")->orWhere('customer_name', 'like', "%$s%");
                if (strlen($digits) >= 4) {
                    $w->orWhere('phone_key', 'like', '%'.ltrim($digits, '0').'%');
                }
            });
        }

        return response()->json(['data' => $q->limit(15)->get()->map(fn (Order $o) => [
            'id' => $o->id, 'reference' => $o->reference(), 'customer_name' => $o->customer_name, 'phone' => $o->phone,
            'city' => $o->shippingCity(), 'amount' => (float) $o->total_price, 'delivered_at' => $o->delivered_at?->toIso8601String(), 'product_name' => $o->productName(),
        ])]);
    }

    public function prefill(Order $order): JsonResponse
    {
        return response()->json($this->sav->prefill($order));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'type' => ['required', Rule::in(array_keys(config('sav.types')))],
            'reason' => ['required', Rule::in(config('sav.reasons'))],
            'comment' => [Rule::requiredIf($request->input('reason') === 'Autre'), 'nullable', 'string', 'max:2000'],
            'sav_note' => ['nullable', 'string', 'max:2000'],
            'pickup' => ['required', 'array', 'min:1'],
            'pickup.*.key' => ['required', 'string'],
            'pickup.*.quantity' => ['required', 'integer', 'min:1'],
            'deliver' => [Rule::requiredIf($request->input('type') === 'echange'), 'array'],
            'deliver.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'deliver.*.quantity' => ['required', 'integer', 'min:1'],
            'driver_id' => ['nullable', 'integer'],
        ], [
            'reason.required' => 'Le motif est obligatoire.',
            'comment.required' => 'Précisez le motif « Autre » en commentaire.',
            'pickup.required' => 'Choisissez au moins un produit à récupérer.',
            'deliver.required' => 'Choisissez le nouveau produit à remettre au client.',
        ]);
        $s = $this->sav->create(Order::findOrFail($data['order_id']), $data, $request->user());

        return response()->json(['message' => "Demande {$s->reference} créée.", 'data' => $this->sav->payload($s)], 201);
    }

    public function show(SavRequest $sav): JsonResponse
    {
        return response()->json(['data' => $this->sav->payload($sav, 'admin', true)]);
    }

    public function assign(Request $request, SavRequest $sav): JsonResponse
    {
        $data = $request->validate(['driver_id' => ['required', 'integer']]);
        $s = $this->sav->assign($sav, (int) $data['driver_id'], $request->user());

        return response()->json(['message' => "Demande attribuée à {$s->driver?->name}. Elle apparaît dans son espace livreur.", 'data' => $this->sav->payload($s, 'admin', true)]);
    }

    public function action(Request $request, SavRequest $sav): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'string'], 'comment' => ['nullable', 'string', 'max:2000'], 'postponed_until' => ['nullable', 'date']]);
        if (in_array($data['action'], ['receive', 'close'], true) && ! $request->user()->can('sav.depot')) {
            abort(403, 'Réception au dépôt / clôture réservées aux responsables.');
        }
        $s = $this->sav->act($sav, $data['action'], 'admin', $request->user(), $data);

        return response()->json(['message' => 'Statut : '.SavRequest::statusLabel($s->status).'.', 'data' => $this->sav->payload($s, 'admin', true)]);
    }

    /** « Articles chez les livreurs »: items in a driver's custody, grouped by driver. */
    public function custody(): JsonResponse
    {
        $items = SavRequestItem::query()->where('state', 'with_driver')
            ->with(['request:id,reference,type,status,driver_id,customer_name,order_id,picked_up_at,assigned_at', 'request.driver:id,name,phone'])
            ->get()->filter(fn ($i) => $i->request && $i->request->driver_id);
        $drivers = $items->groupBy(fn ($i) => $i->request->driver_id)->map(function ($rows) {
            $driver = $rows->first()->request->driver;
            $fmt = fn ($i) => $i->toPayload() + [
                'sav_id' => $i->request->id, 'sav_reference' => $i->request->reference, 'customer_name' => $i->request->customer_name,
                'status_label' => SavRequest::statusLabel($i->request->status), 'since' => ($i->direction === 'pickup' ? $i->request->picked_up_at : $i->request->assigned_at)?->toIso8601String(),
            ];

            return [
                'driver_id' => $driver->id, 'driver_name' => $driver->name, 'driver_phone' => $driver->phone,
                'to_return' => $rows->where('direction', 'pickup')->map($fmt)->values(),
                'to_deliver' => $rows->where('direction', 'deliver')->map($fmt)->values(),
            ];
        })->values();

        return response()->json(['data' => $drivers]);
    }

    public function forOrder(Order $order): JsonResponse
    {
        return response()->json(['data' => SavRequest::query()->where('order_id', $order->id)->latest('id')->get()->map(fn ($s) => $this->sav->payload($s, 'admin', true))->values()]);
    }
}
