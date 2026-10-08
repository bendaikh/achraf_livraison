<?php

namespace App\Http\Controllers;

use App\Models\ConfirmationStatus;
use App\Models\Order;
use App\Models\OrderCall;
use App\Models\OrderDiscount;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\ConfirmationStatusService;
use App\Services\Shopify\ShopifyOrderEditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * T5 — Centre de confirmation: agent / queue stats, queue navigation, call log, discounts.
 * Works on the same orders and confirmation statuses as the Confirmation list.
 */
class ConfirmationCentreController extends Controller
{
    public function __construct(private readonly ConfirmationStatusService $statuses) {}

    private function assertOrderAccessible(Request $request, Order $order): void
    {
        abort_unless(Order::query()->where('company_id', $request->user()->resolveCompanyId())->visibleTo($request->user())->whereKey($order->id)->exists(), 404);
    }

    /** Same filter + search + order as GET /api/confirmation/orders. */
    public static function queueQuery(ConfirmationStatusService $statuses, string $filter, string $search = '', string $agent = '', ?int $me = null): Builder
    {
        $q = Order::query()->inWorkflowQueues();
        $companyId = null;
        if ($user = request()->user()) {
            $q->visibleTo($user);
            $companyId = $user->resolveCompanyId();
            $q->where('company_id', $companyId);
        }
        self::applyAgentFilter($q, $agent, $me);
        $statuses->applyFilter($q, $filter !== '' && $filter !== 'all' ? $filter : ConfirmationStatus::defaultCode($companyId), $companyId);
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return $q->orderByRaw('COALESCE(shopify_created_at, created_at) DESC')->orderByDesc('id');
    }

    /** agent filter: '' (tous) | me | none | user id */
    public static function applyAgentFilter(Builder $q, string $agent, ?int $me): void
    {
        match (true) {
            $agent === '' => null,
            $agent === 'me' => $q->where('assigned_user_id', $me ?? 0),
            $agent === 'none' => $q->whereNull('assigned_user_id'),
            ctype_digit($agent) => $q->where('assigned_user_id', (int) $agent),
            default => null,
        };
    }

    /** GET /api/confirmation/stats — real counters (today + comparisons only when history exists). */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->resolveCompanyId();
        $today = now()->startOfDay();
        $confirmedCodes = ConfirmationStatus::codesWithFlag('counts_as_confirmed', $companyId) ?: [Order::CONFIRMATION_CONFIRMED];
        $failedCodes = ConfirmationStatus::codesWithFlag('counts_as_failure', $companyId);

        $calls = fn (Carbon $from, ?Carbon $to = null, ?int $userId = null) => OrderCall::query()
            ->where('called_at', '>=', $from)->when($to, fn ($q) => $q->where('called_at', '<', $to))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))->count();
        $events = fn (array $codes, Carbon $from, ?Carbon $to = null, ?int $userId = null) => $codes === [] ? 0 : OrderStatusHistory::query()
            ->where('kind', 'confirmation')->whereIn('status_code', $codes)
            ->where('created_at', '>=', $from)->when($to, fn ($q) => $q->where('created_at', '<', $to))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))->count();

        $metric = function (callable $fn) use ($today) {
            $hasHistory = $fn(Carbon::create(2000), $today) > 0;

            return [
                'today' => $fn($today),
                'yesterday' => $hasHistory ? $fn($today->copy()->subDay(), $today) : null,
                'last_7_days' => $hasHistory ? $fn($today->copy()->subDays(7), $today) : null,
                'last_30_days' => $hasHistory ? $fn($today->copy()->subDays(30), $today) : null,
            ];
        };

        $queue = self::queueQuery($this->statuses, ConfirmationStatus::defaultCode($companyId));
        $countBase = Order::query()->inWorkflowQueues()->where('company_id', $companyId)->visibleTo($user);
        $grouped = $this->statuses->groupedStatusRows($countBase);
        $byStatus = [];
        $other = 0;
        foreach (ConfirmationStatus::cachedAll($companyId)->where('is_active', true) as $status) {
            $count = (int) ($grouped[$status->code]['total'] ?? 0);
            $byStatus[] = ['code' => $status->code, 'name' => $status->name, 'count' => $count, 'show_in_filters' => (bool) $status->show_in_filters];
            if (! $status->show_in_filters) {
                $other += $count;
            }
        }

        return response()->json([
            'calls' => $metric(fn ($from, $to = null) => $calls($from, $to)),
            'confirmed' => $metric(fn ($from, $to = null) => $events($confirmedCodes, $from, $to)),
            'failed' => $metric(fn ($from, $to = null) => $events($failedCodes, $from, $to)),
            'to_confirm' => (clone $queue)->count(),
            'postponed_due' => Order::query()->inWorkflowQueues()->where('company_id', $companyId)->whereNotNull('postponed_until')->where('postponed_until', '<=', now())
                ->whereIn('confirmation_status', ConfirmationStatus::codesWithBehavior(ConfirmationStatus::BEHAVIOR_FUTURE_ONLY, $companyId) ?: ['__none__'])->count(),
            'mine' => [
                'calls_today' => $calls($today, null, $user->id),
                'confirmed_today' => $events($confirmedCodes, $today, null, $user->id),
                'failed_today' => $events($failedCodes, $today, null, $user->id),
            ],
            'by_status' => $byStatus,
            'other_statuses' => $other,
            'recall' => $this->statuses->recallBuckets($countBase, $companyId),
        ]);
    }

    /** GET /api/confirmation/orders/{order}/siblings?filter=&search= — previous / next in the queue. */
    public function siblings(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $ids = self::queueQuery($this->statuses, trim((string) $request->query('filter', '')), trim((string) $request->query('search', '')), (string) $request->query('agent', ''), $request->user()->id)
            ->pluck('id')->all();
        $pos = array_search($order->id, $ids, true);

        return response()->json([
            'total' => count($ids),
            'position' => $pos === false ? null : $pos + 1,
            'in_queue' => $pos !== false,
            'prev_id' => $pos !== false && $pos > 0 ? $ids[$pos - 1] : null,
            'next_id' => $pos !== false ? ($ids[$pos + 1] ?? null) : ($ids[0] ?? null),
        ]);
    }

    /** POST /api/confirmation/orders/{order}/calls — « Enregistrer l'appel ». */
    public function logCall(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $data = $request->validate([
            'result' => ['required', 'in:'.implode(',', array_keys(OrderCall::RESULTS))],
            'channel' => ['nullable', 'in:'.implode(',', array_keys(OrderCall::CHANNELS))],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:36000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['result.required' => 'Choisissez le résultat de l’appel.']);

        /** @var User $user */
        $user = $request->user();
        $call = OrderCall::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'result' => $data['result'],
            'channel' => $data['channel'] ?? 'phone',
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'called_at' => now(),
        ]);
        $label = 'Appel enregistré : '.OrderCall::RESULTS[$call->result];
        $order->appendHistory('call_logged', $label, $user, ['call_id' => $call->id, 'result' => $call->result, 'channel' => $call->channel], $call->note);
        $order->save();
        OrderStatusHistory::create([
            'order_id' => $order->id, 'kind' => 'appel', 'status_code' => 'call_'.$call->result,
            'status_name' => OrderCall::RESULTS[$call->result], 'status_color' => '#0ea5e9',
            'data' => ['channel' => $call->channel], 'note' => $call->note, 'user_id' => $user->id,
        ]);

        return response()->json(['message' => 'Appel enregistré.', 'call' => $call->load('user:id,name')->toPayload()], 201);
    }

    /** POST /api/confirmation/orders/{order}/discounts {type: amount|percent, value, reason} */
    public function addDiscount(Request $request, Order $order): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        $data = $request->validate([
            'type' => ['required', 'in:amount,percent'],
            'value' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['value.gt' => 'La remise doit être supérieure à 0.']);
        /** @var User $user */
        $user = $request->user();
        $editor = app(ShopifyOrderEditService::class);
        if ($editor->isConnectedOrder($order)) {
            if (! ($order->shop->capabilities()['order_edit'] ?? false)) {
                throw ValidationException::withMessages(['shopify' => ShopifyOrderEditService::UNAUTHORIZED]);
            }
            $before = (float) $order->total_price;
            $pushed = $editor->pushDiscount($order, $data['type'], (float) $data['value'], $data['reason'] ?? null, $user);
            $updated = $pushed['order'];
            $amount = round($before - (float) $updated->total_price, 2);
            $discount = OrderDiscount::create([
                'order_id' => $updated->id,
                'user_id' => $user->id,
                'type' => $data['type'],
                'value' => $data['value'],
                'amount' => max(0, $amount),
                'reason' => $data['reason'] ?? null,
                'shopify_discount_ids' => ['tag' => $pushed['tag'], 'ids' => $pushed['ids']],
            ]);
            $label = 'Remise de '.$this->dh(max(0, $amount)).($data['type'] === 'percent' ? " ({$data['value']} %)" : '');
            OrderStatusHistory::create([
                'order_id' => $updated->id, 'kind' => 'remise', 'status_code' => 'discount_added', 'status_name' => 'Remise ajoutée',
                'status_color' => '#db2777',
                'data' => ['amount' => $amount, 'type' => $data['type'], 'value' => $data['value'], 'total_from' => $before, 'total_to' => (float) $updated->total_price, 'shopify' => 'pushed'],
                'note' => $label.' · Total '.$this->dh($before).' → '.$this->dh((float) $updated->total_price).($data['reason'] ?? null ? ' · '.$data['reason'] : ''),
                'user_id' => $user->id,
            ]);

            return response()->json(['message' => 'Remise ajoutée.', 'discount' => $discount->load('user:id,name')->toPayload()], 201);
        }

        $discount = DB::transaction(function () use ($order, $data, $user) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $total = (float) $locked->total_price;
            if ($data['type'] === 'percent' && $data['value'] > 100) {
                abort(response()->json(['message' => 'Une remise ne peut pas dépasser 100 %.'], 422));
            }
            $amount = round($data['type'] === 'percent' ? $total * $data['value'] / 100 : (float) $data['value'], 2);
            if ($amount > $total) {
                abort(response()->json(['message' => 'La remise dépasse le total de la commande ('.number_format($total, 2, ',', ' ').' DH).'], 422));
            }
            $discount = OrderDiscount::create(['order_id' => $locked->id, 'user_id' => $user->id, 'type' => $data['type'], 'value' => $data['value'], 'amount' => $amount, 'reason' => $data['reason'] ?? null]);
            $newTotal = round($total - $amount, 2);
            $label = 'Remise de '.$this->dh($amount).($data['type'] === 'percent' ? " ({$data['value']} %)" : '');
            $locked->forceFill([
                'total_price' => $newTotal,
                'discount_total' => round((float) $locked->discount_total + $amount, 2),
                'items_edited_at' => $locked->items_edited_at ?? now(), // keeps the internal total on Shopify re-sync
                'items_edited_by' => $locked->items_edited_by ?? $user->id,
            ]);
            $locked->appendHistory('discount_added', $label, $user, ['discount_id' => $discount->id], $data['reason'] ?? null);
            $locked->save();
            OrderStatusHistory::create([
                'order_id' => $locked->id, 'kind' => 'remise', 'status_code' => 'discount_added', 'status_name' => 'Remise ajoutée',
                'status_color' => '#db2777', 'data' => ['amount' => $amount, 'type' => $data['type'], 'value' => $data['value'], 'total_from' => $total, 'total_to' => $newTotal, 'shopify' => 'not_pushed'],
                'note' => $label.' · Total '.$this->dh($total).' → '.$this->dh($newTotal).($data['reason'] ?? null ? ' · '.$data['reason'] : ''),
                'user_id' => $user->id,
            ]);

            return $discount;
        });

        return response()->json(['message' => 'Remise ajoutée.', 'discount' => $discount->load('user:id,name')->toPayload()], 201);
    }

    /** DELETE /api/confirmation/orders/{order}/discounts/{discount} — kept in history (soft removal). */
    public function removeDiscount(Request $request, Order $order, OrderDiscount $discount): JsonResponse
    {
        $this->assertOrderAccessible($request, $order);
        abort_unless($discount->order_id === $order->id, 404);
        if ($discount->removed_at) {
            return response()->json(['message' => 'Cette remise est déjà retirée.'], 422);
        }
        /** @var User $user */
        $user = $request->user();
        $editor = app(ShopifyOrderEditService::class);
        if ($editor->isConnectedOrder($order)) {
            if (! ($order->shop->capabilities()['order_edit'] ?? false)) {
                throw ValidationException::withMessages(['shopify' => ShopifyOrderEditService::UNAUTHORIZED]);
            }
            $before = (float) $order->total_price;
            $updated = $editor->removePushedDiscount($order, (array) ($discount->shopify_discount_ids ?? []), $user);
            $discount->forceFill(['removed_at' => now(), 'removed_by' => $user->id])->save();
            OrderStatusHistory::create([
                'order_id' => $updated->id, 'kind' => 'remise', 'status_code' => 'discount_removed', 'status_name' => 'Remise retirée',
                'status_color' => '#db2777',
                'data' => ['amount' => $discount->amount, 'total_from' => $before, 'total_to' => (float) $updated->total_price, 'shopify' => 'pushed'],
                'note' => 'Remise retirée · Total '.$this->dh($before).' → '.$this->dh((float) $updated->total_price),
                'user_id' => $user->id,
            ]);

            return response()->json(['message' => 'Remise retirée.']);
        }
        DB::transaction(function () use ($order, $discount, $user) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $total = (float) $locked->total_price;
            $newTotal = round($total + $discount->amount, 2);
            $discount->forceFill(['removed_at' => now(), 'removed_by' => $user->id])->save();
            $locked->forceFill(['total_price' => $newTotal, 'discount_total' => max(0, round((float) $locked->discount_total - $discount->amount, 2))]);
            $locked->appendHistory('discount_removed', 'Remise retirée ('.$this->dh($discount->amount).')', $user, ['discount_id' => $discount->id]);
            $locked->save();
            OrderStatusHistory::create([
                'order_id' => $locked->id, 'kind' => 'remise', 'status_code' => 'discount_removed', 'status_name' => 'Remise retirée',
                'status_color' => '#db2777', 'data' => ['amount' => $discount->amount, 'total_from' => $total, 'total_to' => $newTotal],
                'note' => 'Remise retirée · Total '.$this->dh($total).' → '.$this->dh($newTotal), 'user_id' => $user->id,
            ]);
        });

        return response()->json(['message' => 'Remise retirée.']);
    }

    private function dh(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',').' DH';
    }
}
