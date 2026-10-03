<?php

namespace App\Services\Carriers;

use App\Models\Order;
use App\Models\SpeedafSetting;
use App\Models\SpeedafShipment;
use App\Models\User;
use App\Services\Speedaf\SpeedafException;
use App\Services\Speedaf\SpeedafShipmentService;
use Illuminate\Database\Eloquent\Builder;

/** Speedaf adapter of the generic carrier contract (wraps SpeedafShipmentService). */
class SpeedafCarrier implements CarrierInterface
{
    public function key(): string
    {
        return 'speedaf';
    }

    public function label(): string
    {
        return 'Speedaf';
    }

    public function color(): string
    {
        return '#ea580c';
    }

    protected function service(int $companyId): SpeedafShipmentService
    {
        return SpeedafShipmentService::for(SpeedafSetting::forCompany($companyId));
    }

    public function unavailableReason(int $companyId): ?string
    {
        try {
            $this->service($companyId)->assertReady();
        } catch (SpeedafException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function ship(int $companyId, iterable $orders, ?User $user = null): array
    {
        return array_map(fn (array $r) => [
            'order_id' => $r['order_id'],
            'reference' => $r['reference'],
            'success' => $r['success'],
            'tracking' => $r['bill_code'] ?? null,
            'message' => $r['message'],
        ], $this->service($companyId)->createMany($orders, $user));
    }

    public function shipmentFor(Order $order): ?array
    {
        $shipment = $order->relationLoaded('speedafShipments')
            ? $order->speedafShipments->where('state', '!=', SpeedafShipment::STATE_CANCELLED)->sortByDesc('id')->first()
            : SpeedafShipmentService::activeShipment($order);
        if (! $shipment) {
            return null;
        }

        return [
            'carrier' => $this->key(),
            'carrier_label' => $this->label(),
            'color' => $this->color(),
            'tracking' => $shipment->bill_code,
            'state' => $shipment->state,
            'status_label' => $shipment->last_action_name ?: 'Créée',
            'status_message' => $shipment->last_message,
            'status_at' => $shipment->last_event_at?->toIso8601String(),
            'shipped_at' => $shipment->created_at?->toIso8601String(),
            'last_error' => $shipment->last_error,
            'label_url' => url('/api/speedaf/orders/'.$order->id.'/label'),
            'can_cancel' => $shipment->state === SpeedafShipment::STATE_CREATED,
        ];
    }

    public function labels(int $companyId, iterable $orders): array
    {
        $ids = collect($orders)->pluck('id')->all();
        $shipments = SpeedafShipment::query()->whereIn('order_id', $ids)
            ->where('state', '!=', SpeedafShipment::STATE_CANCELLED)->whereNotNull('bill_code')
            ->with('order')->latest('id')->get()->unique('order_id')->values();
        if ($shipments->isEmpty()) {
            return [];
        }
        try {
            $this->service($companyId)->labels($shipments->all());
        } catch (SpeedafException $e) {
            throw new CarrierException($e->getMessage(), 0, $e);
        }

        return $shipments->map(fn (SpeedafShipment $s) => [
            'order_id' => $s->order_id,
            'reference' => $s->order?->reference(),
            'tracking' => $s->bill_code,
            'pdf_url' => url('/api/speedaf/orders/'.$s->order_id.'/label'),
        ])->all();
    }

    public function scopeShipped(Builder $query): Builder
    {
        return $query->whereHas('speedafShipments', fn ($w) => $w->where('state', '!=', SpeedafShipment::STATE_CANCELLED));
    }
}
