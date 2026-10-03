<?php

namespace App\Services;

use App\Models\Closing;
use App\Models\Driver;
use App\Models\Mission;
use App\Models\Order;
use App\Services\Team\CommissionService;
use App\Support\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Driver cash ("caisse") — COD money and driver commissions are always kept separate.
 *  - COD held  = collected amount of delivered orders + cash collected on standalone missions, not yet closed.
 *  - Commissions = sum of the price snapshots (missions.driver_price) of completed missions, not yet closed.
 */
class ClosingService
{
    public function unclosedDeliveredOrders(?int $driverId = null): Builder
    {
        return Order::query()
            ->whereNotNull('driver_id')
            ->whereNull('closing_id')
            ->whereNotNull('delivered_at')
            ->whereNull('cod_remitted_at')
            ->inDeliveryCategories(['succes'])
            ->when($driverId, fn ($q) => $q->where('driver_id', $driverId));
    }

    /** Completed missions not yet closed (commissions). */
    public function unclosedCompletedMissions(?int $driverId = null): Builder
    {
        return Mission::query()
            ->whereNotNull('driver_id')
            ->whereNull('closing_id')
            ->where('status', 'terminee')
            ->when($driverId, fn ($q) => $q->where('driver_id', $driverId));
    }

    /** Cash collected on standalone missions (ramassage…) — order-linked missions are counted via the order. */
    public function unclosedMissionCash(?int $driverId = null): float
    {
        return (float) $this->unclosedCompletedMissions($driverId)
            ->whereNull('order_id')->where('cash_direction', 'collect')->sum('cash_amount');
    }

    public function pending(?int $driverId = null): array
    {
        $orders = $this->unclosedDeliveredOrders($driverId);
        $missions = $this->unclosedCompletedMissions($driverId);

        return [
            'cod' => round((float) (clone $orders)->sum('amount_collected') + $this->unclosedMissionCash($driverId), 2),
            'commissions' => round((float) (clone $missions)->sum('driver_price'), 2),
            'orders_count' => (clone $orders)->count(),
            'missions_count' => (clone $missions)->count(),
            'oldest_delivered_at' => (clone $orders)->min('delivered_at'),
        ];
    }

    public function pendingByDriver(): array
    {
        return Driver::query()->orderBy('name')->get()->map(function (Driver $d) {
            return ['driver' => ['id' => $d->id, 'name' => $d->name, 'is_active' => $d->is_active]] + $this->pending($d->id);
        })->filter(fn ($row) => $row['orders_count'] > 0 || $row['missions_count'] > 0)->values()->all();
    }

    public function close(Driver $driver, ?float $remitted = null, ?string $note = null): Closing
    {
        return DB::transaction(function () use ($driver, $remitted, $note) {
            $p = $this->pending($driver->id);
            if ($p['orders_count'] === 0 && $p['missions_count'] === 0) {
                throw ValidationException::withMessages(['driver_id' => 'Rien à clôturer pour ce livreur.']);
            }
            $closing = Closing::create([
                'driver_id' => $driver->id,
                'closing_date' => now()->toDateString(),
                'cod_expected' => $p['cod'],
                'cod_remitted' => $remitted ?? $p['cod'],
                'commissions_total' => $p['commissions'],
                'orders_count' => $p['orders_count'],
                'missions_count' => $p['missions_count'],
                'note' => $note,
                'user_id' => CurrentUser::id(),
                'closed_at' => now(),
            ]);
            // cod_remitted_at keeps the existing "COD remis" flag in sync with the closing.
            $this->unclosedDeliveredOrders($driver->id)->update(['closing_id' => $closing->id, 'cod_remitted_at' => $closing->closed_at]);
            // Agent commissions with the « livraison + clôture » trigger (mass update → no model events).
            $commissions = app(CommissionService::class);
            Order::query()->where('closing_id', $closing->id)->get()->each(fn ($o) => $commissions->syncOrder($o));
            $this->unclosedCompletedMissions($driver->id)->update(['closing_id' => $closing->id]);

            return $closing;
        });
    }
}
