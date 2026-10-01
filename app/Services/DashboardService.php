<?php

namespace App\Services;

use App\Models\Closing;
use App\Models\ConfirmationStatus;
use App\Models\DeliveryStatus;
use App\Models\Driver;
use App\Models\Mission;
use App\Models\Order;
use App\Models\Setting;
use App\Support\Catalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Dashboard statistics — computed only from orders, missions, statuses (via their
 * categories) and closings. No second data source and no fake numbers.
 */
class DashboardService
{
    public const PERIODS = ['today' => "Aujourd'hui", 'yesterday' => 'Hier', 'week' => 'Cette semaine', 'month' => 'Ce mois', 'custom' => 'Période personnalisée'];

    protected array $catIds = [];

    public function __construct(protected ClosingService $closings) {}

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    public function resolvePeriod(string $period, ?string $from, ?string $to): array
    {
        $now = Carbon::now();

        return match ($period) {
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), 'yesterday'],
            'week' => [$now->copy()->startOfWeek(Carbon::MONDAY), $now->copy()->endOfDay(), 'week'],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay(), 'month'],
            'custom' => [
                Carbon::parse($from ?: $now->toDateString())->startOfDay(),
                Carbon::parse($to ?: ($from ?: $now->toDateString()))->endOfDay(),
                'custom',
            ],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'today'],
        };
    }

    /** Status codes of the given categories (inactive statuses included, so old orders still count). */
    protected function ids(string ...$categories): array
    {
        $key = implode(',', $categories);

        return $this->catIds[$key] ??= (DeliveryStatus::codesForCategories($categories) ?: ['__none__']);
    }

    /** Confirmation status codes by meaning — read from the configurable confirmation_statuses. */
    protected function conf(string $group): array
    {
        $key = 'conf:'.$group;
        if (isset($this->catIds[$key])) {
            return $this->catIds[$key];
        }
        $all = ConfirmationStatus::query()->get();
        $codes = match ($group) {
            'to_confirm' => $all->where('type', ConfirmationStatus::TYPE_OPEN),
            'confirmed' => $all->where('type', ConfirmationStatus::TYPE_SUCCESS),
            'cancelled' => $all->where('type', ConfirmationStatus::TYPE_CANCELLED),
            'postponed' => $all->where('queue_behavior', ConfirmationStatus::BEHAVIOR_FUTURE_ONLY),
            'no_answer' => $all->where('type', ConfirmationStatus::TYPE_WAITING)->where('queue_behavior', '!=', ConfirmationStatus::BEHAVIOR_FUTURE_ONLY),
            default => collect(),
        };

        return $this->catIds[$key] = ($codes->pluck('code')->values()->all() ?: ['__none__']);
    }

    public function build(string $period = 'today', ?string $from = null, ?string $to = null, ?int $driverId = null): array
    {
        [$start, $end, $period] = $this->resolvePeriod($period, $from, $to);
        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $base = fn (): Builder => Order::query()->when($driverId, fn ($q) => $q->where('driver_id', $driverId));
        // Orders "active" in the period: received or whose status changed during the period.
        $active = fn (): Builder => $base()->where(fn ($q) => $q
            ->whereBetween('created_at', [$start, $end])
            ->orWhereBetween('status_changed_at', [$start, $end]));
        $inCats = fn (Builder $q, string ...$cats) => $q->whereIn('delivery_status', $this->ids(...$cats));

        $cards = [
            'received' => $base()->whereBetween('created_at', [$start, $end])->count(),
            'to_confirm' => $active()->whereIn('confirmation_status', $this->conf('to_confirm'))->count(),
            'confirmed' => $active()->whereIn('confirmation_status', $this->conf('confirmed'))->count(),
            'no_answer' => $active()->where(fn ($q) => $q->whereIn('confirmation_status', $this->conf('no_answer'))
                ->orWhereIn('delivery_status', $this->ids('injoignable')))->count(),
            'postponed' => $active()->where(fn ($q) => $q->whereIn('confirmation_status', $this->conf('postponed'))
                ->orWhereIn('delivery_status', $this->ids('report')))->count(),
            'cancelled' => $active()->where(fn ($q) => $q->whereIn('confirmation_status', $this->conf('cancelled'))
                ->orWhereIn('delivery_status', $this->ids('annulation')))->count(),
            'to_assign' => $active()->whereIn('confirmation_status', $this->conf('confirmed'))->whereNull('driver_id')
                ->where(fn ($q) => $q->whereNull('delivery_status')->orWhereIn('delivery_status', $this->ids('avant_livraison')))->count(),
            'in_delivery' => $inCats($active(), 'en_livraison')->count(),
            'delivered' => $inCats($active(), 'succes')->count(),
        ];

        $processed = $active()->whereNotIn('confirmation_status', $this->conf('to_confirm'))->count();
        $out = $inCats($active(), ...Catalog::OUT_FOR_DELIVERY_CATEGORIES)->count();
        $failed = $inCats($active(), 'echec', 'retour')->count();
        $rate = fn (int $num, int $den) => $den > 0 ? ['value' => round($num * 100 / $den, 1), 'numerator' => $num, 'denominator' => $den] : null;
        $rates = [
            'confirmation' => $rate($cards['confirmed'], $processed),
            'delivery' => $rate($cards['delivered'], $out),
            'failure' => $rate($failed, $out),
        ];

        // Breakdown by configured status (active ones + inactive ones still present).
        $counts = $active()->whereNotNull('delivery_status')->selectRaw('delivery_status, count(*) as c')
            ->groupBy('delivery_status')->pluck('c', 'delivery_status');
        $statusBreakdown = DeliveryStatus::query()->ordered()->get()
            ->filter(fn ($s) => $s->is_active || isset($counts[$s->code]))
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'category' => $s->category, 'count' => (int) ($counts[$s->code] ?? 0)])
            ->values();

        return [
            'period' => [
                'key' => $period, 'label' => self::PERIODS[$period],
                'from' => $start->toDateString(), 'to' => $end->toDateString(),
            ],
            'driver_id' => $driverId,
            'cards' => $cards,
            'rates' => $rates,
            'status_breakdown' => $statusBreakdown,
            'drivers' => $this->driverActivity($start, $end, $driverId, $active),
            'missions' => $this->missionCounters($start, $end, $driverId),
            'cash' => $this->cash($start, $end, $driverId),
            'recent_orders' => $active()->with(['deliveryStatus', 'driver'])->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get()
                ->map(fn (Order $o) => [
                    'id' => $o->id, 'reference' => $o->reference(), 'customer_name' => $o->customer_name, 'city' => $o->shippingCity(),
                    'amount' => (float) $o->total_price, 'confirmation_status' => $o->confirmation_status,
                    'confirmation_status_label' => ConfirmationStatus::labelFor($o->confirmation_status),
                    'confirmation_status_color' => ConfirmationStatus::colorFor($o->confirmation_status),
                    'delivery_status' => $o->deliveryStatus ? ['id' => $o->deliveryStatus->id, 'name' => $o->deliveryStatus->name, 'color' => $o->deliveryStatus->color, 'icon' => $o->deliveryStatus->icon, 'is_active' => $o->deliveryStatus->is_active] : null,
                    'created_at' => $o->created_at?->toIso8601String(),
                ]),
            'alerts' => $this->alerts($driverId),
        ];
    }

    protected function driverActivity(Carbon $start, Carbon $end, ?int $driverId, \Closure $active): array
    {
        $drivers = Driver::query()
            ->when($driverId, fn ($q) => $q->where('id', $driverId), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();

        return $drivers->map(function (Driver $d) use ($start, $end, $active) {
            $q = fn () => $active()->where('driver_id', $d->id);
            $count = fn (string ...$cats) => $q()->whereIn('delivery_status', $this->ids(...$cats))->count();
            $pending = $this->closings->pending($d->id);

            return [
                'id' => $d->id,
                'name' => $d->name,
                'assigned' => $q()->count(),
                'in_progress' => $count('en_livraison'),
                'delivered' => $count('succes'),
                'postponed' => $count('report'),
                'no_answer' => $count('injoignable'),
                'failed' => $count('echec', 'retour'),
                'missions' => Mission::query()->where('driver_id', $d->id)->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])->count(),
                'earnings' => round((float) Mission::query()->where('driver_id', $d->id)->where('status', 'terminee')
                    ->whereBetween('completed_at', [$start, $end])->sum('driver_price'), 2),
                'cod_held' => $pending['cod'],
                'commissions_unclosed' => $pending['commissions'],
                'cash_unclosed' => $pending['orders_count'] + $pending['missions_count'] > 0,
            ];
        })->all();
    }

    protected function missionCounters(Carbon $start, Carbon $end, ?int $driverId): array
    {
        $m = fn () => Mission::query()->when($driverId, fn ($q) => $q->where('driver_id', $driverId));
        $open = Catalog::openMissionStatuses();
        $inRange = fn ($q) => $q->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()]);

        return [
            'pickups_todo' => $inRange($m()->where('type', 'ramassage')->whereIn('status', $open))->count(),
            'deposits_todo' => $inRange($m()->where('type', 'depot_partenaire')->whereIn('status', $open))->count(),
            'returns_todo' => $inRange($m()->whereIn('type', ['retour', 'echange'])->whereIn('status', $open))->count(),
            'completed' => $m()->where('status', 'terminee')->whereBetween('completed_at', [$start, $end])->count(),
        ];
    }

    protected function cash(Carbon $start, Carbon $end, ?int $driverId): array
    {
        $codCollected = (float) Order::query()->when($driverId, fn ($q) => $q->where('driver_id', $driverId))
            ->whereIn('delivery_status', $this->ids('succes'))
            ->whereBetween('delivered_at', [$start, $end])->sum('amount_collected')
            + (float) Mission::query()->when($driverId, fn ($q) => $q->where('driver_id', $driverId))
                ->whereNull('order_id')->where('status', 'terminee')->where('cash_direction', 'collect')
                ->whereBetween('completed_at', [$start, $end])->sum('cash_amount');

        $pending = $this->closings->pending($driverId);
        $closings = fn () => Closing::query()->when($driverId, fn ($q) => $q->where('driver_id', $driverId));
        $closedInPeriod = (float) $closings()->whereBetween('closed_at', [$start, $end])->sum('cod_remitted');
        // Shortfall of past closings (expected − remitted), still owed by drivers.
        $shortfall = (float) $closings()->whereColumn('cod_remitted', '<', 'cod_expected')->get()
            ->sum(fn ($c) => (float) $c->cod_expected - (float) $c->cod_remitted);

        return [
            'cod_collected' => round($codCollected, 2),
            'cod_with_drivers' => $pending['cod'],
            'closed_amount' => round($closedInPeriod, 2),
            'remaining_to_remit' => round($pending['cod'] + $shortfall, 2),
            // Driver remuneration — never mixed with COD.
            'commissions_earned' => round((float) Mission::query()->when($driverId, fn ($q) => $q->where('driver_id', $driverId))
                ->where('status', 'terminee')->whereBetween('completed_at', [$start, $end])->sum('driver_price'), 2),
            'commissions_unclosed' => $pending['commissions'],
        ];
    }

    protected function alerts(?int $driverId): array
    {
        $now = Carbon::now();
        $hours = (int) Setting::getValue('confirmation_alert_hours', 24);
        $orders = fn () => Order::query()->when($driverId, fn ($q) => $q->where('driver_id', $driverId));

        $unclosedDrivers = collect($this->closings->pendingByDriver())
            ->when($driverId, fn ($c) => $c->where('driver.id', $driverId))
            ->filter(fn ($r) => $r['oldest_delivered_at'] && Carbon::parse($r['oldest_delivered_at'])->lt($now->copy()->startOfDay()))
            ->values();

        return [
            [
                'key' => 'stale_confirmations',
                'label' => "Commandes à confirmer depuis plus de {$hours} h",
                'count' => $orders()->whereIn('confirmation_status', $this->conf('to_confirm'))->where('created_at', '<', $now->copy()->subHours($hours))->count(),
                'link' => '/commandes?confirmation_status='.($this->conf('to_confirm')[0]).'&date_to='.$now->copy()->subHours($hours)->toDateString(),
            ],
            [
                'key' => 'due_callbacks',
                'label' => 'Reports / rappels arrivés à échéance',
                'count' => $orders()->where(fn ($q) => $q
                    ->where(fn ($d) => $d->whereIn('delivery_status', $this->ids('report'))->whereNotNull('delivery_postponed_until')->where('delivery_postponed_until', '<=', $now))
                    ->orWhere(fn ($c) => $c->whereIn('confirmation_status', $this->conf('postponed'))->whereNotNull('postponed_until')->where('postponed_until', '<=', $now)))->count(),
                'link' => '/commandes?status_category=report',
            ],
            [
                'key' => 'unclosed_cash',
                'label' => 'Livreurs avec caisse non clôturée',
                'count' => $unclosedDrivers->count(),
                'details' => $unclosedDrivers->map(fn ($r) => $r['driver']['name'].' · '.number_format($r['cod'], 2, ',', ' ').' DH')->all(),
                'link' => '/cloture',
            ],
            [
                'key' => 'orders_without_driver',
                'label' => 'Commandes confirmées sans livreur',
                'count' => $driverId ? 0 : Order::query()->whereIn('confirmation_status', $this->conf('confirmed'))->whereNull('driver_id')
                    ->where(fn ($q) => $q->whereNull('delivery_status')->orWhereIn('delivery_status', $this->ids('avant_livraison')))->count(),
                'link' => '/a-attribuer',
            ],
            [
                'key' => 'late_missions',
                'label' => 'Missions en retard',
                'count' => Mission::query()->when($driverId, fn ($q) => $q->where('driver_id', $driverId))
                    ->whereIn('status', Catalog::openMissionStatuses())->whereDate('scheduled_date', '<', $now->toDateString())->count(),
                'link' => '/missions?status=open&date_to='.$now->copy()->subDay()->toDateString(),
            ],
        ];
    }
}
