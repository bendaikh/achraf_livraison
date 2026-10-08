<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\FlowOrderCreator;
use App\Services\Orders\OrderLifecycle;
use App\Services\Orders\OrderMotifs;
use App\Support\Catalog;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Create a real order, cancel it, or soft-delete a draft. */
class OrderLifecycleController extends Controller
{
    public function __construct(
        private readonly FlowOrderCreator $creator,
        private readonly OrderLifecycle $lifecycle,
    ) {}

    /** GET /api/orders/actions?ids[]= */
    public function actions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);
        $user = $request->user();
        $orders = Order::query()->visibleTo($user)->where('company_id', $user->resolveCompanyId())->whereIn('id', $data['ids'])->get();
        $rows = $orders->map(function (Order $order) use ($user) {
            return [
                'id' => $order->id,
                'reference' => $order->reference(),
                'amount_paid' => (float) $order->amount_paid,
                'payment_method' => $order->paymentMethod(),
            ] + $this->lifecycle->flags($order, $user);
        })->values();

        return response()->json([
            'orders' => $rows,
            'can_cancel' => $rows->contains(fn ($row) => $row['can_cancel']),
            'can_delete_draft' => $rows->contains(fn ($row) => $row['can_delete_draft']),
            'motifs' => collect(OrderMotifs::MOTIFS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    /** GET /api/orders/commercials — active users who can create an order. */
    public function commercials(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('orders.create'), 403);
        $companyId = $request->user()->resolveCompanyId();
        $users = User::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('role', '!=', User::ROLE_LIVREUR)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => Permissions::allows($user, 'orders.create'))
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values();

        return response()->json(['data' => $users]);
    }

    /** POST /api/orders/flow */
    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request);
        $result = $this->creator->create($request->user(), $input);
        $order = $result['order']->load(['deliveryStatus', 'driver', 'assignedUser', 'createdByUser:id,name', 'commercialUser:id,name', 'shop:id,shop_domain,shop_name']);

        return (new OrderResource($order))
            ->additional([
                'message' => $result['notice'] ?: ($result['status'] === 200 ? 'Commande déjà créée.' : 'Commande créée.'),
                'warnings' => $result['warnings'],
                'notice' => $result['notice'],
            ])
            ->response()
            ->setStatusCode($result['status']);
    }

    /** POST /api/orders/{order}/flow-retry */
    public function retry(Request $request, Order $order): JsonResponse
    {
        $this->visible($request, $order);
        $result = $this->creator->retry($request->user(), $order);
        $fresh = $result['order']->load(['shop:id,shop_domain,shop_name', 'createdByUser:id,name', 'commercialUser:id,name']);

        return (new OrderResource($fresh))
            ->additional(['message' => 'Commande créée.', 'warnings' => $result['warnings'], 'notice' => $result['notice']])
            ->response()
            ->setStatusCode($result['status']);
    }

    /** POST /api/orders/{order}/cancel */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->visible($request, $order);
        $data = $this->cancelInput($request);
        $updated = $this->lifecycle->cancel($order, $request->user(), $data['reason'], $data['comment'] ?? null, (bool) ($data['refund'] ?? false));

        return (new OrderResource($updated->load(['shop:id,shop_domain,shop_name', 'histories.user', 'missions'])))
            ->additional(['message' => 'Commande annulée.'])
            ->response();
    }

    /** POST /api/orders/cancel */
    public function cancelMany(Request $request): JsonResponse
    {
        $data = $this->cancelInput($request);
        $ids = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer'],
        ])['order_ids'];

        return response()->json(['results' => $this->each($request, $ids, function (Order $order) use ($request, $data) {
            $this->lifecycle->cancel($order, $request->user(), $data['reason'], $data['comment'] ?? null, (bool) ($data['refund'] ?? false));

            return 'Commande annulée.';
        })]);
    }

    /** DELETE /api/orders/{order}/draft */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->visible($request, $order);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->lifecycle->deleteDraft($order, $request->user(), $reason);

        return response()->json(['message' => 'Brouillon supprimé.']);
    }

    /** POST /api/orders/delete-drafts */
    public function destroyMany(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['results' => $this->each($request, $ids['order_ids'], function (Order $order) use ($request, $ids) {
            $this->lifecycle->deleteDraft($order, $request->user(), $ids['reason'] ?? null);

            return 'Brouillon supprimé.';
        })]);
    }

    /** @param  list<int>  $ids */
    private function each(Request $request, array $ids, callable $action): array
    {
        $user = $request->user();
        $orders = Order::query()->visibleTo($user)->where('company_id', $user->resolveCompanyId())->whereIn('id', $ids)->get()->keyBy('id');
        $results = [];
        foreach ($ids as $id) {
            $order = $orders->get((int) $id);
            if (! $order) {
                $results[] = ['order_id' => (int) $id, 'ok' => false, 'message' => 'Commande introuvable.'];

                continue;
            }
            try {
                $message = $action($order);
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'ok' => true, 'message' => $message];
            } catch (ValidationException $e) {
                $results[] = [
                    'order_id' => $order->id,
                    'reference' => $order->reference(),
                    'ok' => false,
                    'message' => collect($e->errors())->flatten()->first(),
                ];
            }
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'creation_key' => ['required', 'uuid'],
            'draft' => ['sometimes', 'boolean'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'shopify_customer_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0'],
            'discount_kind' => ['nullable', Rule::in(['amount', 'percent'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'shipping_price' => ['nullable', 'numeric', 'min:0'],
            'fees' => ['nullable', 'array'],
            'fees.*.label' => ['required_with:fees', 'string', 'max:120'],
            'fees.*.amount' => ['required_with:fees', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(array_keys(Catalog::PAYMENT_METHODS))],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'payment_label' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'commercial_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }

    /** @return array{reason: string, comment: ?string, refund: bool} */
    private function cancelInput(Request $request): array
    {
        return $request->validate([
            'reason' => ['required', 'string', 'max:80'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'refund' => ['sometimes', 'boolean'],
        ]);
    }

    private function visible(Request $request, Order $order): void
    {
        abort_unless(
            Order::query()->visibleTo($request->user())
                ->where('company_id', $request->user()->resolveCompanyId())
                ->whereKey($order->id)
                ->exists(),
            404,
        );
    }
}
