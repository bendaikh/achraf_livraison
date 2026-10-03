<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentCommission;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Team\CommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Équipe → Performance confirmation & commissions (T6). Agents only see themselves. */
class TeamController extends Controller
{
    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    protected function period(Request $request): array
    {
        $p = (string) $request->query('period', 'month');

        return match ($p) {
            'today' => [now()->startOfDay(), now()->endOfDay(), $p],
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay(), $p],
            'week' => [now()->startOfWeek(), now()->endOfDay(), $p],
            'custom' => [
                Carbon::parse($request->query('from', now()->toDateString()))->startOfDay(),
                Carbon::parse($request->query('to', now()->toDateString()))->endOfDay(), $p,
            ],
            default => [now()->startOfMonth(), now()->endOfDay(), 'month'],
        };
    }

    protected function agents(Request $request)
    {
        $q = User::query()->where('role', '!=', User::ROLE_LIVREUR)->with('service:id,name')->orderBy('name');
        if (! $request->user()->can('team.view_all')) {
            $q->whereKey($request->user()->id);
        } elseif ($request->filled('service_id')) {
            $q->where('service_id', $request->integer('service_id'));
        }

        return $q->get();
    }

    /** GET /api/team/performance?period=today|yesterday|week|month|custom&from&to */
    public function performance(Request $request): JsonResponse
    {
        [$from, $to, $period] = $this->period($request);
        $agents = $this->agents($request);
        $ids = $agents->pluck('id')->all();

        $events = OrderStatusHistory::query()->where('kind', 'confirmation')->whereIn('user_id', $ids)
            ->whereBetween('created_at', [$from, $to])->get(['order_id', 'user_id', 'status_code']);
        $byUser = $events->groupBy('user_id');
        $assigned = Order::query()->whereIn('assigned_user_id', $ids)->whereBetween('created_at', [$from, $to])
            ->selectRaw('assigned_user_id, COUNT(*) as n')->groupBy('assigned_user_id')->pluck('n', 'assigned_user_id');
        $commissions = AgentCommission::query()->whereIn('user_id', $ids)->whereBetween('generated_at', [$from, $to])
            ->selectRaw('user_id, state, SUM(amount) as total')->groupBy('user_id', 'state')->get()->groupBy('user_id');

        $rows = $agents->map(function (User $u) use ($byUser, $assigned, $commissions) {
            $ev = $byUser->get($u->id, collect());
            $distinct = fn (string $code) => $ev->where('status_code', $code)->pluck('order_id')->unique()->count();
            $confirmedIds = $ev->where('status_code', Order::CONFIRMATION_CONFIRMED)->pluck('order_id')->unique()->values();
            $processed = $ev->pluck('order_id')->unique()->count();
            $confirmed = $confirmedIds->count();
            $delivered = $confirmedIds->isEmpty() ? 0 : Order::query()->whereIn('id', $confirmedIds)->inDeliveryCategories(['succes'])->count();
            $c = $commissions->get($u->id, collect())->keyBy('state');
            $sum = fn (string $s) => round((float) ($c[$s]->total ?? 0), 2);

            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'service' => $u->service?->name,
                'commission_mode_label' => config('commissions.modes.'.($u->commission_mode ?: 'none').'.label'),
                'assigned' => (int) ($assigned[$u->id] ?? 0),
                'processed' => $processed,
                'confirmed' => $confirmed,
                'no_answer' => $distinct(Order::CONFIRMATION_NO_ANSWER),
                'postponed' => $distinct(Order::CONFIRMATION_POSTPONED),
                'cancelled' => $distinct(Order::CONFIRMATION_CANCELLED),
                'delivered' => $delivered,
                'confirmation_rate' => $processed ? round($confirmed * 100 / $processed, 1) : null,
                'delivery_rate' => $confirmed ? round($delivered * 100 / $confirmed, 1) : null,
                'commissions_generated' => round($sum('pending') + $sum('validated') + $sum('paid'), 2),
                'commissions_pending' => $sum('pending'),
                'commissions_validated' => $sum('validated'),
                'commissions_paid' => $sum('paid'),
            ];
        })->values();

        return response()->json([
            'period' => ['key' => $period, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'rows' => $rows,
            'can_view_all' => $request->user()->can('team.view_all'),
        ]);
    }

    /** GET /api/team/commissions?state=&user_id=&period= */
    public function commissions(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $q = AgentCommission::query()->with(['user:id,name', 'order', 'validator:id,name', 'payer:id,name'])
            ->whereBetween('generated_at', [$from, $to])->latest('generated_at')->latest('id');
        if (! $request->user()->can('team.view_all')) {
            $q->where('user_id', $request->user()->id);
        } elseif ($request->filled('user_id')) {
            $q->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('state')) {
            $q->where('state', $request->query('state'));
        }
        $page = $q->paginate(min(max($request->integer('per_page', 50), 10), 200));

        return response()->json([
            'data' => collect($page->items())->map->toPayload()->values(),
            'meta' => ['total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage()],
            'totals' => (clone $q)->reorder()->selectRaw('state, SUM(amount) as total')->groupBy('state')->pluck('total', 'state')->map(fn ($v) => round((float) $v, 2)),
            'can_manage' => $request->user()->can('commissions.manage'),
        ]);
    }

    /** POST /api/team/commissions/transition {ids, action: validate|pay|cancel, reason} */
    public function transition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:validate,pay,cancel'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $from = ['validate' => [AgentCommission::PENDING], 'pay' => [AgentCommission::VALIDATED], 'cancel' => [AgentCommission::PENDING, AgentCommission::VALIDATED]][$data['action']];
        $user = $request->user();
        $lines = AgentCommission::query()->whereIn('id', $data['ids'])->whereIn('state', $from)->get();
        foreach ($lines as $line) {
            $line->forceFill(match ($data['action']) {
                'validate' => ['state' => AgentCommission::VALIDATED, 'validated_at' => now(), 'validated_by' => $user->id],
                'pay' => ['state' => AgentCommission::PAID, 'paid_at' => now(), 'paid_by' => $user->id],
                'cancel' => ['state' => AgentCommission::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $data['reason'] ?? 'Annulée par '.$user->name],
            })->save();
        }
        $skipped = count(array_unique($data['ids'])) - $lines->count();
        $verb = ['validate' => 'validée(s)', 'pay' => 'marquée(s) payée(s)', 'cancel' => 'annulée(s)'][$data['action']];

        return response()->json(['message' => $lines->count()." commission(s) {$verb}.".($skipped ? " {$skipped} ignorée(s) (état incompatible)." : ''), 'updated' => $lines->count()]);
    }

    /** POST /api/team/commissions/monthly {month: YYYY-MM} */
    public function monthly(Request $request, CommissionService $service): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $n = $service->generateMonthly(isset($data['month']) ? Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth() : null);

        return response()->json(['message' => $n ? "{$n} fixe(s) mensuel(s) généré(s)." : 'Aucun nouveau fixe mensuel à générer.', 'created' => $n]);
    }
}
