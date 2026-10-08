<?php

namespace App\Services\Delivery;

use App\Models\Order;
use App\Services\Carriers\CarrierInterface;
use App\Services\Carriers\CarrierPresentation;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Support\Carbon;

/**
 * One list for « Envoyer avec », the fiche commande and the per-row « Expédier » popup.
 * Local delivery is an entry in that list, not a separate screen.
 */
class DeliveryModeRegistry
{
    public function __construct(protected CarrierRegistry $carriers) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function modes(int $companyId, bool $canShip, bool $canAssignDriver): array
    {
        $ranks = $this->ranks($companyId);
        $modes = [];
        if ($canAssignDriver) {
            $modes[] = [
                'key' => 'local',
                'type' => 'local',
                'label' => 'Livraison locale',
                'available' => true,
                'reason' => null,
                'logo' => null,
                'color' => '#059669',
                'recent_rank' => $ranks['local'] ?? null,
                'capabilities' => [],
                'actions' => [],
                'documents' => [],
            ];
        }
        if ($canShip) {
            foreach ($this->carriers->options($companyId) as $option) {
                $modes[] = $option + [
                    'type' => 'carrier',
                    'recent_rank' => $ranks[$option['key']] ?? null,
                ];
            }
        }

        return $modes;
    }

    /**
     * What the fiche commande shows: the mode actually used, or an empty mode.
     *
     * @return array<string, mixed>
     */
    public function forOrder(Order $order): array
    {
        $shipment = $this->carriers->shipmentFor($order);
        if ($shipment) {
            $carrier = $this->carriers->get((string) $shipment['carrier']);

            return [
                'type' => 'carrier',
                'key' => $shipment['carrier'],
                'label' => $shipment['carrier_label'] ?? $carrier?->label(),
                'logo' => $carrier instanceof CarrierPresentation ? $carrier->logoUrl() : null,
                'color' => $shipment['color'] ?? $carrier?->color(),
                'tracking' => $shipment['tracking'] ?? null,
                'status_label' => $shipment['status_label'] ?? null,
                'tracking_url' => $shipment['tracking_url'] ?? null,
                'actions' => $this->resolveActions($carrier, $order),
                'history' => $this->history($order),
            ];
        }

        $driver = $order->relationLoaded('driver') ? $order->driver : ($order->driver_id ? $order->driver()->first() : null);
        if ($driver) {
            $mission = $order->relationLoaded('missions') ? $order->missions->sortByDesc('id')->first() : null;

            return [
                'type' => 'local',
                'key' => 'local',
                'label' => 'Livraison locale',
                'logo' => null,
                'color' => '#059669',
                'tracking' => null,
                'status_label' => $order->deliveryStatusLabel(),
                'tracking_url' => null,
                'actions' => [],
                'history' => [],
                'driver' => ['id' => $driver->id, 'name' => $driver->name, 'phone' => $driver->phone],
                'mission' => $mission ? [
                    'id' => $mission->id,
                    'reference' => $mission->reference,
                    'status' => $mission->status,
                    'scheduled_date' => $mission->scheduled_date instanceof \DateTimeInterface ? $mission->scheduled_date->format('Y-m-d') : ($mission->scheduled_date ?: null),
                    'assigned_at' => $mission->assigned_at?->toIso8601String(),
                    'driver_price' => $mission->driver_price !== null ? (float) $mission->driver_price : null,
                ] : null,
            ];
        }

        return $this->empty();
    }

    /** @return array<string, mixed> */
    protected function empty(): array
    {
        return [
            'type' => null,
            'key' => null,
            'label' => null,
            'logo' => null,
            'color' => null,
            'tracking' => null,
            'status_label' => null,
            'tracking_url' => null,
            'actions' => [],
            'history' => [],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function resolveActions(?CarrierInterface $carrier, Order $order): array
    {
        if (! $carrier instanceof CarrierPresentation) {
            return [];
        }
        $actions = [];
        foreach ($carrier->actions() as $action) {
            $action['url'] = str_replace('{id}', (string) $order->id, (string) ($action['url'] ?? ''));
            $actions[] = $action;
        }

        return $actions;
    }

    /** @return list<array<string, mixed>> */
    protected function history(Order $order): array
    {
        $loaded = $order->relationLoaded('speedafShipments') || $order->relationLoaded('ozonShipments') || $order->relationLoaded('siftShipments');
        if (! $loaded) {
            return [];
        }
        $rows = [];
        foreach ($this->carriers->all() as $carrier) {
            if ($carrier instanceof CarrierPresentation) {
                foreach ($carrier->history($order) as $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /** @return array<string, int> key => rank (1 = most used over 30 days) */
    protected function ranks(int $companyId): array
    {
        $since = Carbon::now()->subDays(30);
        $counts = [
            'local' => Order::query()->where('company_id', $companyId)->whereNotNull('driver_id')->where('assigned_at', '>=', $since)->count(),
        ];
        foreach ($this->carriers->all() as $key => $carrier) {
            $counts[$key] = $carrier instanceof CarrierPresentation ? $carrier->recentCount($companyId, $since) : 0;
        }
        $rank = 1;
        $out = [];
        foreach (collect($counts)->filter(fn ($n) => $n > 0)->sortDesc() as $key => $n) {
            $out[$key] = $rank++;
        }

        return $out;
    }
}
