<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OzonShipment;
use App\Models\SavRequest;
use App\Services\Ozon\OzonDuplicateException;
use App\Services\Ozon\OzonException;
use App\Services\Ozon\OzonShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Commandes / fiche commande / Retours → Ozon Express actions (refresh, BL, labels, SAV exchange). */
class OzonOrderController extends Controller
{
    public const MAX_BULK = 50;

    protected function service(Request $request): OzonShipmentService
    {
        return OzonShipmentService::forCompany($request->user()->resolveCompanyId());
    }

    protected function orderPayload(Order $order): array
    {
        return (new OrderResource($order->fresh()->load(['deliveryStatus', 'driver', 'assignedUser', 'assignedByUser:id,name', 'shop:id,shop_domain,shop_name', 'missions.driver', 'histories.user', 'speedafShipments', 'ozonShipments.deliveryNote'])))->resolve();
    }

    protected function shipmentOf(Order $order, Request $request): OzonShipment
    {
        $id = $request->input('shipment_id');
        $q = OzonShipment::query()->where('order_id', $order->id)->whereNotNull('tracking_number');
        $shipment = $id ? $q->whereKey($id)->first() : $q->active()->whereNull('sav_request_id')->latest('id')->first();
        abort_unless($shipment, 404, 'Aucun colis Ozon pour cette commande.');

        return $shipment;
    }

    /** POST /api/ozon/orders/{order}/refresh — « Actualiser depuis Ozon » (parcel-info + tracking). */
    public function refresh(Request $request, Order $order): JsonResponse
    {
        $shipment = $this->shipmentOf($order, $request);
        try {
            $this->service($request)->refreshParcelInfo($shipment, $request->user());
        } catch (OzonException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => $this->orderPayload($order)], 422);
        }

        return response()->json(['message' => 'Colis actualisé depuis Ozon.', 'data' => $this->orderPayload($order)]);
    }

    /** POST /api/ozon/orders/{order}/track — single status lookup. */
    public function track(Request $request, Order $order): JsonResponse
    {
        $shipment = $this->shipmentOf($order, $request);
        try {
            $changed = $this->service($request)->refreshTracking($shipment, $request->user());
        } catch (OzonException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => $this->orderPayload($order)], 422);
        }

        return response()->json(['message' => $changed ? 'Statut mis à jour depuis Ozon.' : 'Suivi vérifié : statut inchangé.', 'data' => $this->orderPayload($order)]);
    }

    protected function selection(Request $request): array
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'order_ids.*' => ['integer'],
        ], ['order_ids.required' => 'Sélectionnez au moins une commande.', 'order_ids.max' => 'Maximum '.self::MAX_BULK.' commandes.']);

        return array_values(array_unique($data['order_ids']));
    }

    protected function bulkGate(array $ids): ?JsonResponse
    {
        if (count($ids) > 1 && ! OzonShipmentService::bulkEnabled()) {
            return response()->json(['message' => 'Actions groupées Ozon désactivées tant que le cycle complet n’est pas validé (Intégrations → Ozon Express).'], 422);
        }

        return null;
    }

    /** POST /api/ozon/delivery-notes {order_ids} — « Créer BL Ozon ». */
    public function createDeliveryNote(Request $request): JsonResponse
    {
        $ids = $this->selection($request);
        if ($blocked = $this->bulkGate($ids)) {
            return $blocked;
        }
        $orders = Order::query()->whereIn('id', $ids)->get();
        try {
            $note = $this->service($request)->createDeliveryNote($orders, $request->user());
        } catch (OzonException $e) {
            return response()->json(['message' => str_replace("\n", ' ', $e->getMessage())], 422);
        }

        return response()->json([
            'message' => "BL Ozon {$note->ref} créé et enregistré ({$note->parcels_count} colis).",
            'delivery_note' => $note->load(['items.order', 'creator'])->toSummary(true),
        ]);
    }

    /** POST /api/ozon/labels {order_ids} — « Étiquettes Ozon »: BLs of the selection + PDF links. */
    public function labels(Request $request): JsonResponse
    {
        $ids = $this->selection($request);
        if ($blocked = $this->bulkGate($ids)) {
            return $blocked;
        }
        $shipments = OzonShipment::query()->whereIn('order_id', $ids)->active()->whereNull('sav_request_id')
            ->whereNotNull('tracking_number')->with(['order', 'deliveryNote'])->get();
        $notes = [];
        $withoutNote = [];
        foreach ($shipments as $s) {
            if ($s->deliveryNote && $s->deliveryNote->state === 'saved') {
                $notes[$s->deliveryNote->id] ??= $s->deliveryNote->toSummary() + ['orders' => []];
                $notes[$s->deliveryNote->id]['orders'][] = $s->order?->reference();
            } else {
                $withoutNote[] = $s->order?->reference();
            }
        }
        $notOzon = count($ids) - $shipments->count();
        if (! $notes) {
            return response()->json(['message' => $shipments->isEmpty()
                ? 'Aucune des commandes sélectionnées n’a été envoyée à Ozon.'
                : 'Créez d’abord le BL Ozon de ces commandes (« Créer BL Ozon ») : les étiquettes sont générées par BL.', 'delivery_notes' => [], 'without_note' => $withoutNote], 422);
        }

        return response()->json([
            'message' => count($notes).' BL avec étiquettes.'.($withoutNote ? ' Sans BL : '.implode(', ', $withoutNote).'.' : '').($notOzon > 0 ? " {$notOzon} commande(s) non Ozon ignorée(s)." : ''),
            'delivery_notes' => array_values($notes),
            'without_note' => $withoutNote,
        ]);
    }

    /* ------------------------------------------------------------------ SAV exchange */

    /** GET /api/sav/{sav}/ozon — validation popup for an exchange parcel. */
    public function savPreview(Request $request, SavRequest $sav): JsonResponse
    {
        $options = $request->validate(['price' => ['nullable', 'numeric', 'min:0'], 'open' => ['nullable', 'boolean'], 'fragile' => ['nullable', 'boolean']]);

        return response()->json(['rows' => [$this->service($request)->preview($sav->order, array_filter($options, fn ($v) => $v !== null), $sav)]]);
    }

    /** POST /api/sav/{sav}/ozon — sends the exchange (parcel-replace = 1). */
    public function savSend(Request $request, SavRequest $sav): JsonResponse
    {
        $options = $request->validate(['price' => ['nullable', 'numeric', 'min:0'], 'open' => ['sometimes', 'boolean'], 'fragile' => ['sometimes', 'boolean']]);
        try {
            $shipment = $this->service($request)->createParcel($sav->order, $request->user(), array_filter($options, fn ($v) => $v !== null), $sav);
        } catch (OzonDuplicateException $e) {
            return response()->json(['message' => $e->getMessage(), 'duplicate' => true, 'shipment' => $e->shipment->toSummary()], 422);
        } catch (OzonException $e) {
            return response()->json(['message' => str_replace("\n", ' ', $e->getMessage())], 422);
        }

        return response()->json(['message' => "Échange envoyé à Ozon (n° {$shipment->tracking_number}).", 'shipment' => $shipment->toSummary()]);
    }
}
