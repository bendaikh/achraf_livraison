<?php

namespace App\Services;

use App\Models\Closing;
use App\Models\ConfirmationStatus;
use App\Models\DeliveryStatus;
use App\Models\Mission;
use App\Models\Order;
use App\Models\OzonShipment;
use App\Models\Setting;
use App\Models\SpeedafShipment;
use App\Models\User;
use App\Services\Catalog\CatalogLookup;
use App\Support\Catalog;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Real counters of the « Centre » cards (config/centre.php). Every number comes from the
 * orders / missions / closings tables — never a demo value.
 */
class CentreService
{
    /** Categories of an order that is finished (no more action expected). */
    public const CLOSED_CATEGORIES = ['succes', 'retour', 'annulation'];

    /** Hours after assignment before a local delivery is considered late. */
    public const LATE_AFTER_HOURS = 48;

    public function __construct(protected ConfirmationStatusService $confirmation) {}

    /** @return list<array<string, mixed>> */
    public function cards(?User $user): array
    {
        $hidden = (array) Setting::getValue('centre_hidden_cards', []);
        $out = [];
        foreach ((array) config('centre.cards', []) as $key => $card) {
            if (in_array($key, $hidden, true)) {
                continue;
            }
            if (! empty($card['permission']) && ! Permissions::allows($user, $card['permission'])) {
                continue;
            }
            $counter = $card['counter'] ?? null;
            $value = $counter && method_exists($this, $counter) ? $this->{$counter}() : null;
            $out[] = [
                'key' => $key,
                'title' => $card['title'],
                'description' => $card['description'] ?? null,
                'icon' => $card['icon'] ?? 'Circle',
                'color' => $card['color'] ?? '#2563eb',
                'to' => $card['to'] ?? null,
                'count' => is_array($value) ? $value['count'] : $value,
                'breakdown' => is_array($value) ? ($value['breakdown'] ?? null) : null,
            ];
        }

        return $out;
    }

    /* ------------------------------------------------------------------ counters */

    public function toConfirm(): int
    {
        $q = Order::query();
        $this->confirmation->applyFilter($q, ConfirmationStatus::defaultCode());

        return $q->count();
    }

    /** Confirmed, not shipped to a carrier, waiting for (re)assignment / preparation. */
    public function toProcess(): int
    {
        return Order::query()->awaitingAssignment()->whereDoesntHave('speedafShipments', $this->activeShipment())
            ->whereDoesntHave('ozonShipments', $this->activeOzon())->count();
    }

    /** Parcels currently with an external carrier (sent, not delivered / returned / cancelled). */
    public function atCarriers(): int
    {
        return Order::query()
            ->where(fn ($w) => $w->whereHas('speedafShipments', fn ($q) => $q->where('state', SpeedafShipment::STATE_CREATED))
                ->orWhereHas('ozonShipments', fn ($q) => $q->where('state', OzonShipment::STATE_CREATED)->whereNull('sav_request_id')))
            ->where(fn ($q) => $q->whereNull('delivery_status')->orWhereNotIn('delivery_status', $this->codes(self::CLOSED_CATEGORIES)))
            ->count();
    }

    public function localInProgress(): int
    {
        return Order::query()->activeWithDriver()->count();
    }

    /** @return array{count:int, breakdown: array<string, int>} */
    public function followUp(): array
    {
        $noAnswer = Order::query()->inDeliveryCategories(['injoignable'])->count();
        $postponed = Order::query()->inDeliveryCategories(['report'])->count();
        $problem = Order::query()->inDeliveryCategories(['echec'])->count();
        $late = $this->lateQuery()->count();

        return [
            'count' => $noAnswer + $postponed + $problem + $late,
            'breakdown' => ['Pas de réponse' => $noAnswer, 'Reportées' => $postponed, 'En retard' => $late, 'Problème livraison' => $problem],
        ];
    }

    /** Overdue: postponed date passed, or with a driver for more than 48 h without delivery. */
    public function lateQuery(): Builder
    {
        return Order::query()->activeWithDriver()->where(function ($q) {
            $q->where(fn ($w) => $w->whereNotNull('delivery_postponed_until')->where('delivery_postponed_until', '<', now()))
                ->orWhere(fn ($w) => $w->whereNull('delivery_postponed_until')->where('assigned_at', '<', now()->subHours(self::LATE_AFTER_HOURS)));
        });
    }

    /** Delivered orders whose cash is not closed / remitted yet. */
    public function paymentsToReconcile(): int
    {
        return Order::query()->inDeliveryCategories(['succes'])->whereNull('closing_id')->whereNull('cod_remitted_at')->count();
    }

    public function transactionsToday(): int
    {
        return Closing::query()->where('created_at', '>=', now()->startOfDay())->count();
    }

    public function returns(): int
    {
        $orders = Order::query()->inDeliveryCategories(['retour'])->whereNull('closing_id')->count();
        $missions = Mission::query()->where('type', 'retour')->whereIn('status', Catalog::openMissionStatuses())->whereNull('order_id')->count();

        return $orders + $missions;
    }

    public function exchanges(): int
    {
        return Mission::query()->where('type', 'echange')->whereIn('status', Catalog::openMissionStatuses())->count();
    }

    public function toRetry(): int
    {
        return Order::query()->inDeliveryCategories(Catalog::RETRY_CATEGORIES)->count();
    }

    public function outOfStock(): int
    {
        return count($this->outOfStockOrderIds());
    }

    /** Open (not shipped / not finished) orders with at least one out-of-stock catalog variant. */
    public function outOfStockOrderIds(): array
    {
        $lookup = app(CatalogLookup::class);
        $ids = [];
        Order::query()
            ->select(['id', 'line_items', 'delivery_status'])
            ->where(fn ($q) => $q->whereNull('delivery_status')->orWhereIn('delivery_status', $this->codes(['avant_livraison'])))
            ->whereNotIn('confirmation_status', ConfirmationStatus::codesOfType(ConfirmationStatus::TYPE_CANCELLED) ?: ['__none__'])
            ->whereDoesntHave('speedafShipments', $this->activeShipment())
            ->whereDoesntHave('ozonShipments', $this->activeOzon())
            ->whereNull('driver_id')
            ->chunkById(500, function ($orders) use ($lookup, &$ids) {
                $lookup->prime($orders);
                foreach ($orders as $order) {
                    if ($lookup->hasOutOfStock($order)) {
                        $ids[] = $order->id;
                    }
                }
            });

        return $ids;
    }

    protected function activeOzon(): \Closure
    {
        return fn ($q) => $q->where('state', '!=', OzonShipment::STATE_CANCELLED)->whereNull('sav_request_id');
    }

    protected function activeShipment(): \Closure
    {
        return fn ($q) => $q->where('state', '!=', SpeedafShipment::STATE_CANCELLED);
    }

    protected function codes(array $categories): array
    {
        return DeliveryStatus::codesForCategories($categories) ?: ['__none__'];
    }
}
