<?php

namespace App\Services\Carriers;

use App\Models\Order;
use App\Models\SiftSetting;
use App\Models\SiftShipment;
use App\Models\User;
use App\Services\Sift\SiftException;
use App\Services\Sift\SiftShipmentService;
use App\Services\Sift\SiftStatusMap;
use Illuminate\Database\Eloquent\Builder;

/** Sift.ma adapter of the generic carrier contract (wraps SiftShipmentService). */
class SiftCarrier implements AdvancedCarrier, CarrierInterface
{
    public function key(): string
    {
        return 'sift';
    }

    public function label(): string
    {
        return SiftShipmentService::LABEL;
    }

    public function color(): string
    {
        return SiftShipmentService::COLOR;
    }

    protected function service(int $companyId): SiftShipmentService
    {
        return SiftShipmentService::forCompany($companyId);
    }

    public function unavailableReason(int $companyId): ?string
    {
        try {
            $this->service($companyId)->assertReady();
        } catch (SiftException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function capabilities(int $companyId): array
    {
        $bulk = SiftShipmentService::bulkEnabled();

        return [
            'preview' => true,
            'delivery_notes' => false,
            'bulk_enabled' => $bulk,
            'bulk_reason' => $bulk ? null : 'Actions groupées Sift désactivées tant que le cycle complet n’est pas validé (Intégrations → Transporteurs → Sift.ma).',
            'waybill_formats' => SiftSetting::WAYBILL_FORMATS,
            'default_waybill_format' => SiftSetting::forCompany($companyId)->waybill_format ?: 'STANDARD_100x100',
        ];
    }

    public function preview(int $companyId, iterable $orders, array $options = []): array
    {
        $service = $this->service($companyId);
        $rows = [];
        foreach ($orders as $order) {
            $rows[] = $service->preview($order, $options);
        }

        return $rows;
    }

    public function ship(int $companyId, iterable $orders, ?User $user = null): array
    {
        return $this->shipWithOptions($companyId, $orders, $user, []);
    }

    public function shipWithOptions(int $companyId, iterable $orders, ?User $user, array $options): array
    {
        return $this->service($companyId)->createMany($orders, $user, $options);
    }

    public function shipmentFor(Order $order): ?array
    {
        $shipment = $order->relationLoaded('siftShipments')
            ? $order->siftShipments->sortByDesc('id')->first(fn (SiftShipment $s) => $s->isActive())
            : SiftShipmentService::activeShipment($order);
        if (! $shipment) {
            return null;
        }

        return [
            'carrier' => $this->key(),
            'carrier_label' => $this->label(),
            'color' => $this->color(),
            'tracking' => $shipment->tracking_number ?: $shipment->parcel_id,
            'state' => $shipment->state,
            'status_label' => $shipment->raw_status ? SiftStatusMap::label($shipment->raw_status) : 'Créé',
            'status_message' => trim(($shipment->raw_sub_status ? $shipment->raw_sub_status.' ' : '').($shipment->raw_status_comment ?? '')) ?: null,
            'status_at' => $shipment->status_at?->toIso8601String(),
            'shipped_at' => $shipment->created_at?->toIso8601String(),
            'last_error' => $shipment->last_error,
            'label_url' => $shipment->parcel_id ? '/api/sift/orders/'.$order->id.'/waybill' : null,
            'delivery_note_ref' => null,
            'can_cancel' => false,
            'can_refresh' => true,
        ];
    }

    /** Proxy links (the API key never reaches the browser): /api/sift/orders/{id}/waybill?format=… */
    public function labels(int $companyId, iterable $orders, ?string $format = null): array
    {
        $format = array_key_exists((string) $format, SiftSetting::WAYBILL_FORMATS) ? $format : (SiftSetting::forCompany($companyId)->waybill_format ?: 'STANDARD_100x100');
        $ids = collect($orders)->pluck('id')->all();
        $shipments = SiftShipment::query()->whereIn('order_id', $ids)->active()->whereNotNull('parcel_id')
            ->with('order')->latest('id')->get()->unique('order_id');

        return $shipments->map(fn (SiftShipment $s) => [
            'order_id' => $s->order_id,
            'reference' => $s->order?->reference(),
            'tracking' => $s->tracking_number ?: $s->parcel_id,
            'pdf_url' => '/api/sift/orders/'.$s->order_id.'/waybill?format='.$format,
        ])->values()->all();
    }

    public function scopeShipped(Builder $query): Builder
    {
        return $query->whereHas('siftShipments', fn ($w) => $w->where('state', '!=', SiftShipment::STATE_CANCELLED)->whereNull('hidden_at'));
    }
}
