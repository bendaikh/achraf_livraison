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
class SpeedafCarrier implements CarrierInterface, CarrierPresentation
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

    public function logoUrl(): ?string
    {
        return '/images/carriers/speedaf.svg';
    }

    public function actions(): array
    {
        return [
            ['key' => 'refresh', 'label' => 'Actualiser statut', 'method' => 'POST', 'url' => '/api/speedaf/orders/{id}/sync'],
            ['key' => 'label', 'label' => 'Étiquette', 'method' => 'GET', 'url' => '/api/speedaf/orders/{id}/label'],
            ['key' => 'cancel', 'label' => 'Annuler le colis', 'method' => 'POST', 'url' => '/api/speedaf/orders/{id}/cancel', 'prompt' => 'Motif de l’annulation :', 'prompt_default' => 'Annulation expéditeur'],
        ];
    }

    public function documents(): array
    {
        return [
            ['key' => 'labels', 'label' => 'Étiquettes', 'method' => 'POST', 'url' => '/api/speedaf/labels'],
        ];
    }

    public function history(Order $order): array
    {
        $rows = $order->relationLoaded('speedafShipments')
            ? $order->speedafShipments
            : SpeedafShipment::query()->where('order_id', $order->id)->get();

        return $rows->filter(fn (SpeedafShipment $s) => $s->state === SpeedafShipment::STATE_CANCELLED)
            ->sortByDesc('id')->values()->map(fn (SpeedafShipment $s) => [
                'carrier' => $this->key(),
                'carrier_label' => $this->label(),
                'tracking' => $s->bill_code,
                'status_label' => 'Annulée',
                'state' => $s->state,
                'shipped_at' => $s->created_at?->toIso8601String(),
            ])->all();
    }

    public function recentCount(int $companyId, \DateTimeInterface $since): int
    {
        return SpeedafShipment::query()->where('company_id', $companyId)->where('created_at', '>=', $since)->count();
    }
}
