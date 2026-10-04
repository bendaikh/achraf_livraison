<?php

namespace App\Services\Carriers;

use App\Models\Order;
use App\Models\OzonShipment;
use App\Models\User;
use App\Services\Ozon\OzonException;
use App\Services\Ozon\OzonShipmentService;
use Illuminate\Database\Eloquent\Builder;

/** Ozon Express adapter of the generic carrier contract (wraps OzonShipmentService). */
class OzonCarrier implements AdvancedCarrier, CarrierInterface
{
    public function key(): string
    {
        return 'ozon';
    }

    public function label(): string
    {
        return OzonShipmentService::LABEL;
    }

    public function color(): string
    {
        return OzonShipmentService::COLOR;
    }

    protected function service(int $companyId): OzonShipmentService
    {
        return OzonShipmentService::forCompany($companyId);
    }

    public function unavailableReason(int $companyId): ?string
    {
        try {
            $this->service($companyId)->assertReady();
        } catch (OzonException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function capabilities(int $companyId): array
    {
        $bulk = OzonShipmentService::bulkEnabled();

        return [
            'preview' => true,
            'delivery_notes' => true,
            'bulk_enabled' => $bulk,
            'bulk_reason' => $bulk ? null : 'Actions groupées Ozon désactivées tant que le cycle complet n’est pas validé (Intégrations → Ozon Express).',
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
        $shipment = $order->relationLoaded('ozonShipments')
            ? $order->ozonShipments->first(fn (OzonShipment $s) => $s->isActive() && ! $s->sav_request_id)
            : OzonShipmentService::activeShipment($order);
        if (! $shipment) {
            return null;
        }
        $note = $shipment->delivery_note_id ? $shipment->deliveryNote : null;

        return [
            'carrier' => $this->key(),
            'carrier_label' => $this->label(),
            'color' => $this->color(),
            'tracking' => $shipment->tracking_number,
            'state' => $shipment->state,
            'status_label' => $shipment->raw_status ?: 'Créé',
            'status_message' => $shipment->raw_status_comment,
            'status_at' => $shipment->status_at?->toIso8601String(),
            'shipped_at' => $shipment->created_at?->toIso8601String(),
            'last_error' => $shipment->last_error,
            'label_url' => $note && $note->state === 'saved' ? ($note->documentUrls()['labels_10x10'] ?? null) : null,
            'delivery_note_ref' => $note?->ref,
            'can_cancel' => false,
        ];
    }

    public function labels(int $companyId, iterable $orders): array
    {
        $ids = collect($orders)->pluck('id')->all();
        $shipments = OzonShipment::query()->whereIn('order_id', $ids)->active()->whereNull('sav_request_id')
            ->whereNotNull('tracking_number')->with(['order', 'deliveryNote'])->latest('id')->get()->unique('order_id');
        $rows = [];
        $withoutNote = [];
        foreach ($shipments as $s) {
            if ($s->deliveryNote && $s->deliveryNote->state === 'saved') {
                $rows[] = ['order_id' => $s->order_id, 'reference' => $s->order?->reference(), 'tracking' => $s->tracking_number,
                    'pdf_url' => $s->deliveryNote->documentUrls()['labels_10x10']];
            } else {
                $withoutNote[] = $s->order?->reference();
            }
        }
        if ($rows === [] && $withoutNote) {
            throw new CarrierException('étiquettes disponibles après la création du BL Ozon ('.implode(', ', $withoutNote).').');
        }

        return $rows;
    }

    public function scopeShipped(Builder $query): Builder
    {
        return $query->whereHas('ozonShipments', fn ($w) => $w->where('state', '!=', OzonShipment::STATE_CANCELLED)->whereNull('sav_request_id'));
    }
}
