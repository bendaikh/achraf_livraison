<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryStatusResource;
use App\Http\Resources\OrderResource;
use App\Models\ConfirmationStatus;
use App\Models\DeliveryStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\SpeedafShipment;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Catalog\CatalogLookup;
use App\Services\CentreService;
use App\Services\OrderWorkflow;
use App\Services\Shopify\ShopifyOrderEditService;
use App\Support\Catalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Commandes (admin). Lists Shopify-synced and manual orders with the configurable
 * delivery / confirmation statuses.
 */
class OrderController extends Controller
{
    public function __construct(protected OrderWorkflow $workflow) {}

    public function index(Request $request)
    {
        $q = Order::query()->with(['deliveryStatus', 'driver', 'assignedUser', 'shop:id,shop_domain,shop_name', 'speedafShipments', 'ozonShipments.deliveryNote', 'siftShipments']);

        $this->applyFilters($q, $request);

        $perPage = min(max($request->integer('per_page', 25), 5), 100);

        $page = $q->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
        app(CatalogLookup::class)->prime($page->getCollection());

        return OrderResource::collection($page);
    }

    /** Table & Kanban share exactly the same filters (T12). */
    protected function applyFilters(Builder $q, Request $request): void
    {
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
            match ((string) $request->query('driver_id')) {
                'none' => $q->whereNull('driver_id'),
                'any' => $q->whereNotNull('driver_id'),
                default => $q->where('driver_id', $request->integer('driver_id')),
            };
        }
        // Centre shortcuts
        if ($request->boolean('out_of_stock')) {
            $q->whereIn('id', app(CentreService::class)->outOfStockOrderIds() ?: [0]);
        }
        if ($request->boolean('late')) {
            $q->whereIn('id', app(CentreService::class)->lateQuery()->select('id'));
        }
        if ($request->filled('speedaf')) {
            $active = fn ($w) => $w->where('state', '!=', SpeedafShipment::STATE_CANCELLED);
            $request->query('speedaf') === '1'
                ? $q->whereHas('speedafShipments', $active)
                : $q->whereDoesntHave('speedafShipments', $active);
        }
        if ($request->filled('carrier')) {
            app(CarrierRegistry::class)->applyFilter($q, (string) $request->query('carrier'));
        }
        if ($request->filled('payment_method')) {
            $request->query('payment_method') === 'paye'
                ? $q->where('financial_status', 'paid')
                : $q->where(fn ($w) => $w->whereNull('financial_status')->orWhere('financial_status', '!=', 'paid'));
        }
        if ($request->filled('city')) {
            $q->where('shipping_address->city', 'like', '%'.trim((string) $request->query('city')).'%');
        }
        if ($request->filled('assigned_user_id')) {
            $request->query('assigned_user_id') === 'none'
                ? $q->whereNull('assigned_user_id')
                : $q->where('assigned_user_id', $request->integer('assigned_user_id'));
        }
        if ($request->filled('period')) {
            [$from, $to] = match ((string) $request->query('period')) {
                'today' => [now()->startOfDay(), now()->endOfDay()],
                'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
                '7d' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
                '30d' => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
                'month' => [now()->startOfMonth(), now()->endOfDay()],
                default => [null, null],
            };
            if ($from) {
                $q->whereBetween('created_at', [$from, $to]);
            }
        }
        if ($request->filled('date_from')) {
            $q->where('created_at', '>=', Carbon::parse($request->query('date_from'))->startOfDay());
        }
        if ($request->filled('date_to')) {
            $q->where('created_at', '<=', Carbon::parse($request->query('date_to'))->endOfDay());
        }

        if ($request->filled('source')) {
            $q->where('source', $request->query('source'));
        }
    }

    /**
     * GET /api/orders/kanban — one column per active delivery status (Paramètres → Statuts, ordered),
     * real filtered counts + first cards. Orders without status sit in the first "avant livraison" column.
     * ?column=<code>&offset=n loads more cards of one column.
     */
    public function kanban(Request $request)
    {
        $statuses = DeliveryStatus::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->with('transitionsFrom')->get();
        $nullCode = $statuses->firstWhere('category', 'avant_livraison')?->code ?? $statuses->first()?->code;
        $limit = min(max($request->integer('limit', 30), 5), 100);
        $base = Order::query();
        $this->applyFilters($base, $request);
        $counts = (clone $base)->selectRaw('delivery_status, COUNT(*) c')->groupBy('delivery_status')->pluck('c', 'delivery_status');
        $scope = function ($q, string $code) use ($nullCode) {
            $code === $nullCode ? $q->where(fn ($w) => $w->where('delivery_status', $code)->orWhereNull('delivery_status')) : $q->where('delivery_status', $code);
        };
        $only = $request->query('column');
        $offset = max(0, $request->integer('offset', 0));
        $catalog = app(CatalogLookup::class);
        $columns = [];
        foreach ($statuses as $st) {
            if ($only && $only !== $st->code) {
                continue;
            }
            $count = (int) ($counts[$st->code] ?? 0) + ($st->code === $nullCode ? (int) ($counts[''] ?? 0) : 0);
            $q = (clone $base)->with(['deliveryStatus', 'driver', 'assignedUser', 'shop:id,shop_domain,shop_name', 'speedafShipments', 'ozonShipments.deliveryNote', 'siftShipments']);
            $scope($q, $st->code);
            $orders = $q->orderByDesc('created_at')->orderByDesc('id')->skip($offset)->take($limit)->get();
            $catalog->prime($orders);
            $columns[] = [
                'status' => (new DeliveryStatusResource($st))->resolve(),
                'count' => $count,
                'orders' => OrderResource::collection($orders)->resolve(),
                'has_more' => $offset + $orders->count() < $count,
            ];
        }

        return response()->json([
            'columns' => $columns,
            'transitions_enforced' => $this->workflow->transitionsEnforced(),
        ]);
    }

    /** POST /api/orders/bulk-status — same validations as a manual change, per order (T12 bulk). */
    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer'],
            'delivery_status_id' => ['required', 'integer', 'exists:delivery_statuses,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'postponed_date' => ['nullable', 'date'],
            'postponed_time' => ['nullable', 'date_format:H:i'],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $status = DeliveryStatus::findOrFail($data['delivery_status_id']);
        if (! empty($data['postponed_date'])) {
            $data['postponed_at'] = Carbon::parse($data['postponed_date'].' '.($data['postponed_time'] ?? '09:00'))->format('Y-m-d H:i');
        }
        $ok = 0;
        $failed = [];
        foreach (Order::query()->whereIn('id', $data['order_ids'])->get() as $order) {
            try {
                $this->workflow->changeStatus($order, $status, $data, $request->user());
                $ok++;
            } catch (ValidationException $e) {
                $failed[] = ['id' => $order->id, 'reference' => $order->reference(), 'message' => collect($e->errors())->flatten()->first()];
            }
        }
        $msg = "{$ok} commande(s) passée(s) en « {$status->name} ».".($failed ? ' '.count($failed).' refusée(s).' : '');

        return response()->json(['message' => $msg, 'updated' => $ok, 'failed' => $failed], $ok || ! $failed ? 200 : 422);
    }

    /** POST /api/orders/assign-agent {order_ids, user_id|null} — confirmation agent (bulk). */
    public function assignAgent(Request $request)
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:500'],
            'order_ids.*' => ['integer'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $agent = $data['user_id'] ? User::query()->find($data['user_id']) : null;
        if ($agent && ($agent->isLivreur() || $agent->is_active === false)) {
            return response()->json(['message' => 'Cet utilisateur ne peut pas recevoir de commandes.'], 422);
        }
        $user = $request->user();
        $n = 0;
        foreach (Order::query()->whereIn('id', $data['order_ids'])->with('assignedUser:id,name')->get() as $order) {
            if ((int) $order->assigned_user_id === (int) ($agent?->id)) {
                continue;
            }
            $from = $order->assignedUser?->name;
            $order->assigned_user_id = $agent?->id;
            $label = $agent ? 'Assignée à '.$agent->name.($from ? " (avant : {$from})" : '') : 'Agent retiré'.($from ? " ({$from})" : '');
            $order->appendHistory('agent_assigned', $label, $user);
            $order->save();
            OrderStatusHistory::create([
                'order_id' => $order->id, 'kind' => 'agent', 'status_code' => $agent ? 'agent_assigned' : 'agent_removed',
                'status_name' => $agent ? 'Agent assigné' : 'Agent retiré', 'status_color' => '#0891b2',
                'data' => ['from' => $from, 'to' => $agent?->name], 'note' => $label, 'user_id' => $user->id,
            ]);
            $n++;
        }

        return response()->json(['message' => $agent ? "{$n} commande(s) assignée(s) à {$agent->name}." : "Agent retiré de {$n} commande(s).", 'updated' => $n]);
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

        $editor = app(ShopifyOrderEditService::class);
        if ($editor->isConnectedOrder($order)) {
            $shopify = $this->shopifyCustomerChanges($order, $data);
            if ($shopify !== []) {
                if (! $request->user()?->can('orders.edit_shopify_customer')) {
                    abort(403, 'Vous n’avez pas le droit de modifier le client Shopify.');
                }
                if (! ($order->shop->capabilities()['orders_write'] ?? false)) {
                    throw ValidationException::withMessages(['shopify' => ShopifyOrderEditService::UNAUTHORIZED]);
                }
                $order = $editor->updateCustomer($order, $shopify, $request->user());
                foreach (['customer_phone', 'email', 'note', 'city', 'address'] as $key) {
                    unset($data[$key]);
                }
            }
        }

        $order->fill(Order::attributesFromForm($data, $order))->save();
        if ($hasDriver && (int) $driverId !== (int) $order->driver_id) {
            $this->workflow->assignDriver($order, $driverId, $request->user());
        }

        return new OrderResource($order->fresh()->load($this->detailRelations()));
    }

    /**
     * Phone, email, note and shipping address that differ from the stored order.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function shopifyCustomerChanges(Order $order, array $data): array
    {
        $fields = [];
        if (array_key_exists('customer_phone', $data) && (string) $data['customer_phone'] !== (string) $order->phone) {
            $fields['phone'] = $data['customer_phone'];
        }
        if (array_key_exists('email', $data) && (string) ($data['email'] ?? '') !== (string) ($order->email ?? '')) {
            $fields['email'] = $data['email'];
        }
        if (array_key_exists('note', $data) && (string) ($data['note'] ?? '') !== (string) ($order->note ?? '')) {
            $fields['note'] = $data['note'];
        }
        $city = $order->shipping_address['city'] ?? null;
        $address = $order->shipping_address['address1'] ?? null;
        $cityChanged = array_key_exists('city', $data) && (string) ($data['city'] ?? '') !== (string) ($city ?? '');
        $addressChanged = array_key_exists('address', $data) && (string) ($data['address'] ?? '') !== (string) ($address ?? '');
        if ($cityChanged || $addressChanged) {
            $fields['shipping_address'] = [
                'address1' => array_key_exists('address', $data) ? $data['address'] : $address,
                'city' => array_key_exists('city', $data) ? $data['city'] : $city,
                'phone' => $fields['phone'] ?? $order->phone,
            ];
        }

        return $fields;
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
        return ['deliveryStatus', 'driver', 'assignedUser', 'assignedByUser:id,name', 'shop:id,shop_domain,shop_name', 'missions.driver', 'histories.user', 'speedafShipments', 'ozonShipments.deliveryNote', 'siftShipments', 'fulfillments'];
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
