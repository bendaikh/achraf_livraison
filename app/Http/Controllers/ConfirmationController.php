<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\ClientBlock;
use App\Models\ConfirmationStatus;
use App\Models\Order;
use App\Models\OrderCall;
use App\Models\User;
use App\Services\ConfirmationStatusService;
use App\Services\OrderWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ConfirmationController extends Controller
{
    public function __construct(
        private readonly ConfirmationStatusService $statuses,
        private readonly OrderWorkflow $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $search = trim((string) $request->query('search', ''));
        $filter = trim((string) $request->query('filter', ''));

        $defaultCode = ConfirmationStatus::defaultCode();
        if ($filter === '' || $filter === 'all') {
            $filter = $defaultCode;
        }

        $agent = (string) $request->query('agent', '');
        $query = Order::query()
            ->with(['shop:id,shop_domain,shop_name', 'assignedUser:id,name'])
            ->orderByRaw('COALESCE(shopify_created_at, created_at) DESC')->orderByDesc('id');

        $this->statuses->applyFilter($query, $filter);
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

        $countBase = Order::query();
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
            'counts' => $this->statuses->filterCounts($countBase),
            'statuses' => $this->statuses->activeFilters(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'filter' => $filter,
                'default_filter' => $defaultCode,
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['shop:id,shop_domain,shop_name', 'confirmedByUser:id,name', 'confirmationActedByUser:id,name']);

        return response()->json([
            'order' => $this->detailPayload($order),
            'statuses' => $this->statuses->activeFilters(),
        ]);
    }

    public function confirm(Request $request, Order $order): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $status = ConfirmationStatus::requireByCode(Order::CONFIRMATION_CONFIRMED);
        $channel = $request->validate(['channel' => ['nullable', 'in:'.implode(',', array_keys(OrderCall::CHANNELS))]])['channel'] ?? null;

        $order->forceFill([
            'confirmation_status' => $status->code,
            'confirmation_channel' => $channel ?? 'phone',
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
            'confirmation_acted_by' => $user->id,
            'confirmation_acted_at' => now(),
            'postponed_until' => null,
            'cancellation_reason' => null,
        ]);

        $order->appendHistory('confirmed', $status->name, $user);
        $order->save();
        // Status history + initial delivery status (« À attribuer ») when confirmed.
        $this->workflow->recordConfirmation($order, $status, $user);

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => 'Commande confirmée.',
        ]);
    }

    public function noAnswer(Request $request, Order $order): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $status = ConfirmationStatus::requireByCode(Order::CONFIRMATION_NO_ANSWER);

        $order->forceFill([
            'confirmation_status' => $status->code,
            'confirmation_acted_by' => $user->id,
            'confirmation_acted_at' => now(),
            'postponed_until' => null,
        ]);

        $order->appendHistory('no_answer', $status->name, $user);
        $order->save();
        // Status history + initial delivery status (« À attribuer ») when confirmed.
        $this->workflow->recordConfirmation($order, $status, $user);

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => 'Statut mis à jour : '.$status->name.'.',
        ]);
    }

    public function postpone(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'recall_at' => ['required', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'recall_at.required' => 'La date et l’heure du rappel sont obligatoires.',
            'recall_at.after' => 'Le rappel doit être dans le futur.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $status = ConfirmationStatus::requireByCode(Order::CONFIRMATION_POSTPONED);
        $recallAt = Carbon::parse($data['recall_at']);
        $note = isset($data['note']) ? trim((string) $data['note']) : '';

        $order->forceFill([
            'confirmation_status' => $status->code,
            'postponed_until' => $recallAt,
            'confirmation_acted_by' => $user->id,
            'confirmation_acted_at' => now(),
        ]);

        if ($note !== '') {
            $order->internal_note = trim(implode("\n", array_filter([
                $order->internal_note,
                '[Report] '.$note,
            ])));
        }

        $label = sprintf(
            'Reportée au %s',
            $recallAt->timezone(config('app.timezone'))->format('d/m/Y H:i'),
        );

        $order->appendHistory('postponed', $label, $user, [
            'recall_at' => $recallAt->toIso8601String(),
            'note' => $note !== '' ? $note : null,
        ], $note !== '' ? $note : null);
        $order->save();
        // Status history + initial delivery status (« À attribuer ») when confirmed.
        $this->workflow->recordConfirmation($order, $status, $user);

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => 'Commande reportée.',
        ]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Le motif d’annulation est obligatoire.',
            'reason.min' => 'Le motif d’annulation est trop court.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $status = ConfirmationStatus::requireByCode(Order::CONFIRMATION_CANCELLED);
        $reason = trim($data['reason']);
        $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';

        $order->forceFill([
            'confirmation_status' => $status->code,
            'cancellation_reason' => $reason,
            'confirmation_acted_by' => $user->id,
            'confirmation_acted_at' => now(),
            'postponed_until' => null,
        ]);

        $historyComment = $comment !== '' ? $reason.' — '.$comment : $reason;

        $order->appendHistory(
            'cancelled',
            $status->name,
            $user,
            ['reason' => $reason, 'comment' => $comment !== '' ? $comment : null],
            $historyComment,
        );
        $order->save();
        // Status history + initial delivery status (« À attribuer ») when confirmed.
        $this->workflow->recordConfirmation($order, $status, $user);

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => "Commande annulée dans Lav'Fast Flow.",
        ]);
    }

    public function updateInternalNote(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'internal_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $order->forceFill([
            'internal_note' => isset($data['internal_note']) ? trim((string) $data['internal_note']) : null,
        ])->save();

        return response()->json([
            'order' => $this->detailPayload($order->fresh(['shop', 'confirmedByUser', 'confirmationActedByUser'])),
            'message' => 'Note interne enregistrée.',
        ]);
    }

    private function listPayload(Order $order): array
    {
        $due = $order->isDueForConfirmation();
        $definition = $order->confirmationStatusDefinition();
        $label = $definition?->name ?? Order::confirmationLabel((string) $order->confirmation_status);

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
            'shipping_price' => $order->shipping_price,
            'currency' => $order->currency,
            'line_items' => $order->line_items ?? [],
            'note' => $order->note,
            'confirmation_status' => $order->confirmation_status,
            'confirmation_status_label' => $label,
            'confirmation_status_color' => $definition?->color ?? ConfirmationStatus::colorFor($order->confirmation_status),
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
            'cancellation_reason' => $order->cancellation_reason,
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
}
