<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\ClientBlock;
use App\Models\Company;
use App\Models\ConfirmationStatus;
use App\Models\Order;
use App\Models\OrderCall;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Catalog\CatalogLookup;
use App\Services\Clients\ClientService;
use App\Services\Confirmation\ConfirmationStatusChanger;
use App\Services\ConfirmationStatusService;
use App\Services\OrderWorkflow;
use App\Services\Shopify\ShopifyOrderEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class ConfirmationController extends Controller
{
    public function __construct(
        private readonly ConfirmationStatusService $statuses,
        private readonly OrderWorkflow $workflow,
        private readonly ConfirmationStatusChanger $changer,
        private readonly ClientService $clients,
        private readonly CatalogLookup $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $search = trim((string) $request->query('search', ''));
        $filter = trim((string) $request->query('filter', ''));

        $companyId = $request->user()->resolveCompanyId();
        $defaultCode = ConfirmationStatus::defaultCode($companyId);
        if ($filter === '' || $filter === 'all') {
            $filter = $defaultCode;
        }

        $agent = (string) $request->query('agent', '');
        $bucket = (string) $request->query('bucket', '');
        $query = Order::query()
            ->inWorkflowQueues()
            ->where('company_id', $companyId)
            ->visibleTo($request->user())
            ->with(['shop:id,shop_domain,shop_name', 'assignedUser:id,name'])
            ->orderByRaw('COALESCE(shopify_created_at, created_at) DESC')->orderByDesc('id');

        $this->statuses->applyFilter($query, $filter, $companyId);
        if (in_array($bucket, ['overdue', 'today', 'upcoming'], true)) {
            $this->statuses->applyRecallBucket($query, $bucket, $companyId);
        }
        ConfirmationCentreController::applyAgentFilter($query, $agent, $request->user()?->id);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        $orders = collect($paginator->items())->map(fn (Order $order) => $this->listPayload($order));

        $countBase = Order::query()->inWorkflowQueues()->where('company_id', $companyId)->visibleTo($request->user());
        ConfirmationCentreController::applyAgentFilter($countBase, $agent, $request->user()?->id);
        if ($search !== '') {
            $countBase->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'orders' => $orders,
            'counts' => $this->statuses->filterCounts($countBase, $companyId),
            'statuses' => $this->statuses->activeAll($companyId),
            'tabs' => $this->statuses->activeFilters($companyId),
            'recall' => $this->statuses->recallBuckets($countBase, $companyId),
            'breakdown' => $this->breakdown($countBase, $companyId),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'filter' => $filter,
                'bucket' => $bucket,
                'default_filter' => $defaultCode,
            ],
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $order->load(['shop:id,shop_domain,shop_name', 'confirmedByUser:id,name', 'confirmationActedByUser:id,name']);
        $companyId = (int) ($order->company_id ?: request()->user()->resolveCompanyId());

        return response()->json([
            'order' => $this->detailPayload($order),
            'statuses' => $this->statuses->activeAll($companyId),
        ]);
    }

    public function changeStatus(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $data = $request->validate([
            'status_code' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'recall_at' => ['nullable', 'string', 'max:40'],
            'recall_time' => ['nullable', 'string', 'max:8'],
            'product_line_key' => ['nullable', 'string', 'max:120'],
            'variant_id' => ['nullable'],
            'expected_restock_date' => ['nullable', 'date'],
            'channel' => ['nullable', 'string', 'max:32'],
        ]);

        $order = $this->changer->changeByCode($order, $data['status_code'], $request->user(), $data);

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => 'Statut de confirmation mis à jour.',
        ]);
    }

    public function confirm(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $data = $request->validate([
            'channel' => ['nullable', 'in:'.implode(',', array_keys(OrderCall::CHANNELS))],
        ]);
        $status = $this->changer->quickStatus('confirm', $this->companyId($order));
        $order = $this->changer->change($order, $status, $request->user(), [
            'channel' => $data['channel'] ?? 'phone',
        ]);

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => 'Commande confirmée.',
        ]);
    }

    public function noAnswer(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $status = $this->changer->quickStatus('no_answer', $this->companyId($order));
        $order = $this->changer->change($order, $status, $request->user(), [
            'channel' => 'phone',
            'force' => true,
        ]);

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => 'Statut mis à jour : '.$status->name.'.',
        ]);
    }

    public function postpone(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $data = $request->validate([
            'recall_at' => ['nullable', 'string', 'max:40'],
            'recall_time' => ['nullable', 'string', 'max:8'],
            'note' => ['nullable', 'string', 'max:1000'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $status = $this->changer->quickStatus('postpone', $this->companyId($order));
        $order = $this->changer->change($order, $status, $request->user(), [
            'recall_at' => $data['recall_at'] ?? null,
            'recall_time' => $data['recall_time'] ?? null,
            'comment' => $data['comment'] ?? $data['note'] ?? null,
        ]);

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => 'Commande reportée.',
        ]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $status = $this->changer->quickStatus('cancel', $this->companyId($order));
        $order = $this->changer->change($order, $status, $request->user(), $data);

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => 'Confirmation annulée.',
        ]);
    }

    public function updateInternalNote(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $request->validate([
            'internal_note' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->updateNotes($request, $order);
    }

    public function updateNotes(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $data = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'confirmation_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        if (array_key_exists('note', $data)) {
            $editor = app(ShopifyOrderEditService::class);
            $next = $data['note'] !== null ? trim((string) $data['note']) : null;
            if ($editor->isConnectedOrder($order) && (string) $next !== (string) ($order->note ?? '')) {
                if (! $request->user()?->can('orders.edit_shopify_customer')) {
                    abort(403, 'Vous n’avez pas le droit de modifier la note Shopify.');
                }
                $order = $editor->updateCustomer($order, ['note' => $next], $request->user());
            } else {
                $order->note = $next;
            }
        }
        if (array_key_exists('internal_note', $data)) {
            $order->internal_note = $data['internal_note'] !== null ? trim((string) $data['internal_note']) : null;
        }
        if (array_key_exists('confirmation_note', $data)) {
            $order->confirmation_note = $data['confirmation_note'] !== null ? trim((string) $data['confirmation_note']) : null;
        }
        $order->save();

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => 'Notes enregistrées.',
        ]);
    }

    private function listPayload(Order $order): array
    {
        $due = $order->isDueForConfirmation();
        $definition = $order->confirmationStatusDefinition();
        $label = $definition?->name ?? Order::confirmationLabel((string) $order->confirmation_status, $order->company_id);

        if ($due && $definition?->queue_behavior === ConfirmationStatus::BEHAVIOR_FUTURE_ONLY) {
            $label = 'À rappeler';
        }

        return [
            'id' => $order->id,
            'name' => $order->name,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'phone' => $order->phone,
            'client_blocked' => ($b = ClientBlock::activeFor($order->phone_key)) ? ['reason' => $b->reason] : null,
            'city' => $order->shippingCity(),
            'address' => $order->shippingAddressLine(),
            'total_price' => $order->total_price,
            'amount_paid' => (float) ($order->amount_paid ?? 0),
            'amount_due' => $order->amountDue(),
            'payment_method' => $order->paymentMethod(),
            'payment_label' => $order->paymentLabel(),
            'payment_indicator' => $order->paymentCollectIndicator(),
            'source' => $order->source,
            'source_kind' => $order->sourceKind(),
            'shipping_price' => $order->shipping_price,
            'currency' => $order->currency,
            'line_items' => $order->line_items ?? [],
            'note' => $order->note,
            'confirmation_status' => $order->confirmation_status,
            'confirmation_status_label' => $label,
            'confirmation_status_color' => $definition?->color ?? ConfirmationStatus::colorFor($order->confirmation_status, $order->company_id),
            'confirmation_inactive' => $definition ? ! $definition->is_active : false,
            'confirmation_is_terminal' => $definition?->is_terminal ?? false,
            'is_due' => $due,
            'can_act' => $order->canPerformConfirmationActions(),
            'postponed_until' => $order->postponed_until?->toIso8601String(),
            'assigned_user_id' => $order->assigned_user_id,
            'assigned_user_name' => $order->assignedUser?->name,
            'shopify_created_at' => $order->shopify_created_at?->toIso8601String(),
            'received_at' => ($order->shopify_created_at ?? $order->created_at)?->toIso8601String(),
        ];
    }

    private function detailPayload(Order $order): array
    {
        return array_merge($this->listPayload($order), [
            'email' => $order->email,
            'shipping_address' => $order->shipping_address,
            'internal_note' => $order->internal_note,
            'confirmation_note' => $order->confirmation_note,
            'cancellation_reason' => $order->cancellation_reason,
            'client_history' => $this->clientHistory($order),
            'products' => $this->catalog->enrichOrder($order),
            'history_lines' => $this->historyLines($order),
            'timeline' => $this->timeline($order),
            'confirmation_history' => $order->confirmation_history ?? [],
            'confirmed_by' => $order->confirmed_by,
            'confirmed_by_name' => $order->confirmedByUser?->name,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'confirmation_acted_by' => $order->confirmation_acted_by,
            'confirmation_acted_by_name' => $order->confirmationActedByUser?->name,
            'confirmation_acted_at' => $order->confirmation_acted_at?->toIso8601String(),
            'shop_name' => $order->shop?->shop_name,
            'financial_status' => $order->financial_status,
            'fulfillment_status' => $order->fulfillment_status,
            'status' => $order->status,
            // T5 — Centre de confirmation
            'confirmation_channel' => $order->confirmation_channel,
            'discount_total' => (float) $order->discount_total,
            'calls' => $order->calls()->with('user:id,name')->limit(50)->get()->map->toPayload()->values(),
            'discounts' => $order->discounts()->with('user:id,name')->get()->map->toPayload()->values(),
            'full' => (new OrderResource($order->loadMissing(['deliveryStatus', 'driver', 'assignedUser', 'speedafShipments', 'ozonShipments.deliveryNote', 'siftShipments'])))->resolve(),
        ]);
    }

    private function assertOrderAccessible(Request $request, Order $order): void
    {
        abort_unless(Order::query()->where('company_id', $request->user()->resolveCompanyId())->visibleTo($request->user())->whereKey($order->id)->exists(), 404);
    }

    private function companyId(Order $order): int
    {
        return (int) ($order->company_id ?: request()->user()->resolveCompanyId());
    }

    /** @return array<string, int> */
    private function breakdown(\Illuminate\Database\Eloquent\Builder $base, int $companyId): array
    {
        $rows = $this->statuses->groupedStatusRows($base);
        $items = [];
        $other = 0;
        foreach (ConfirmationStatus::cachedAll($companyId)->where('is_active', true) as $status) {
            $count = (int) ($rows[$status->code]['total'] ?? 0);
            $items[] = [
                'code' => $status->code,
                'name' => $status->name,
                'category' => $status->category,
                'show_in_filters' => (bool) $status->show_in_filters,
                'count' => $count,
            ];
            if (! $status->show_in_filters) {
                $other += $count;
            }
        }

        return ['items' => $items, 'other_statuses' => $other];
    }

    /** @return array{orders: int, previous: int, delivered: int, cancelled: int, returned: int}|null */
    private function clientHistory(Order $order): ?array
    {
        if (! $order->phone_key) {
            return null;
        }
        $row = $this->clients->find($order->phone_key);
        if (! $row) {
            return ['orders' => 0, 'previous' => 0, 'delivered' => 0, 'cancelled' => 0, 'returned' => 0];
        }

        return [
            'orders' => (int) $row->orders,
            'previous' => max(0, (int) $row->orders - 1),
            'delivered' => (int) $row->delivered,
            'cancelled' => (int) $row->cancelled,
            'returned' => (int) $row->returned,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function historyLines(Order $order): array
    {
        return OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->where('kind', 'confirmation')
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (OrderStatusHistory $row) => [
                'id' => $row->id,
                'at' => $row->created_at?->toIso8601String(),
                'user_name' => $row->user?->name,
                'status_code' => $row->status_code,
                'status_name' => $row->status_name,
                'data' => $row->data,
                'formatted' => $this->changer->formatHistoryLine($row, $row->user?->name, $order->company_id),
            ])
            ->all();
    }

    /** @return list<array{at: string, kind: string, label: string}> */
    private function timeline(Order $order): array
    {
        $tz = Company::query()->find($order->company_id)?->timezoneOrDefault() ?? 'Africa/Casablanca';
        $events = [];

        foreach ($order->calls()->with('user:id,name')->limit(50)->get() as $call) {
            $at = $call->called_at ?? $call->created_at;
            $events[] = [
                'at' => $at?->toIso8601String(),
                'kind' => 'call',
                'label' => ($at?->timezone($tz)->format('H:i') ?? '').' – Appel par '.($call->user?->name ?? 'Agent').' – '.(OrderCall::RESULTS[$call->result] ?? $call->result),
            ];
        }

        if (Schema::hasTable('whatsapp_conversation_order')) {
            $conversationIds = \Illuminate\Support\Facades\DB::table('whatsapp_conversation_order')
                ->where('order_id', $order->id)
                ->pluck('whatsapp_conversation_id');
            if ($conversationIds->isNotEmpty()) {
                foreach (WhatsAppMessage::query()->whereIn('whatsapp_conversation_id', $conversationIds)->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)->orderBy('id')->limit(30)->get() as $message) {
                    $events[] = [
                        'at' => $message->created_at?->toIso8601String(),
                        'kind' => 'whatsapp',
                        'label' => ($message->created_at?->timezone($tz)->format('H:i') ?? '').' – WhatsApp envoyé',
                    ];
                }
            }
        }

        if ($order->postponed_until && $order->postponed_until->gt(now())) {
            $when = $order->postponed_until->timezone($tz);
            $prefix = $when->isSameDay(now()->timezone($tz)->addDay()) ? 'Demain '.$when->format('H:i') : $when->format('d/m/Y H:i');
            $events[] = [
                'at' => $order->postponed_until->toIso8601String(),
                'kind' => 'recall',
                'label' => $prefix.' – Rappel programmé',
            ];
        }

        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $events;
    }
}
