<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\SiftSetting;
use App\Models\SiftShipment;
use App\Services\Carriers\SiftCarrier;
use App\Services\Sift\SiftException;
use App\Services\Sift\SiftShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Fiche commande / Commandes → Sift.ma actions (refresh, edit, cancel, hide, waybill, resync). */
class SiftOrderController extends Controller
{
    public const MAX_BULK = 50;

    protected function service(Request $request): SiftShipmentService
    {
        return SiftShipmentService::forCompany($request->user()->resolveCompanyId());
    }

    protected function orderPayload(Order $order): array
    {
        return (new OrderResource($order->fresh()->load(['deliveryStatus', 'driver', 'assignedUser', 'assignedByUser:id,name', 'shop:id,shop_domain,shop_name', 'missions.driver', 'histories.user', 'speedafShipments', 'ozonShipments.deliveryNote', 'siftShipments'])))->resolve();
    }

    /** The shipment designated by ?shipment_id (any state, not hidden) or the active one. */
    protected function shipmentOf(Order $order, Request $request): SiftShipment
    {
        $id = $request->input('shipment_id');
        $q = SiftShipment::query()->where('order_id', $order->id)->whereNull('hidden_at');
        $shipment = $id ? $q->whereKey($id)->first() : (clone $q)->active()->latest('id')->first();
        abort_unless($shipment, 404, 'Aucun colis Sift pour cette commande.');

        return $shipment;
    }

    protected function fail(SiftException $e, Order $order): JsonResponse
    {
        return response()->json(['message' => str_replace("\n", ' ', $e->getMessage()), 'data' => $this->orderPayload($order)], 422);
    }

    /** POST /api/sift/orders/{order}/refresh — « Actualiser depuis Sift ». */
    public function refresh(Request $request, Order $order): JsonResponse
    {
        $shipment = $this->shipmentOf($order, $request);
        try {
            $changed = $this->service($request)->refreshParcel($shipment, $request->user());
        } catch (SiftException $e) {
            return $this->fail($e, $order);
        }

        return response()->json(['message' => $changed ? 'Statut mis à jour depuis Sift.' : 'Colis actualisé depuis Sift.', 'data' => $this->orderPayload($order)]);
    }

    /** PUT /api/sift/orders/{order} — edit name / phone / address / city / COD / notes (pending only). */
    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'receiver' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:250'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'cod_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:250'],
        ]);
        $shipment = $this->shipmentOf($order, $request);
        try {
            $this->service($request)->updateParcel($shipment, $data, $request->user());
        } catch (SiftException $e) {
            return $this->fail($e, $order);
        }

        return response()->json(['message' => 'Colis modifié chez Sift.', 'data' => $this->orderPayload($order)]);
    }

    /** POST /api/sift/orders/{order}/cancel — « Annuler chez le transporteur » (status cancelled). */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $reason = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:250']])['reason'] ?? null;
        $shipment = $this->shipmentOf($order, $request);
        try {
            $this->service($request)->cancelParcel($shipment, $request->user(), $reason);
        } catch (SiftException $e) {
            return $this->fail($e, $order);
        }

        return response()->json(['message' => 'Colis annulé chez Sift. La commande peut être renvoyée.', 'data' => $this->orderPayload($order)]);
    }

    /** POST /api/sift/orders/{order}/hide — « Supprimer / masquer localement » (DELETE = soft delete at Sift). */
    public function hide(Request $request, Order $order): JsonResponse
    {
        $shipment = $this->shipmentOf($order, $request);
        try {
            $this->service($request)->hideParcel($shipment, $request->user());
        } catch (SiftException $e) {
            return $this->fail($e, $order);
        }

        return response()->json(['message' => 'Colis Sift supprimé / masqué.', 'data' => $this->orderPayload($order)]);
    }

    /** POST /api/sift/orders/{order}/resync — find the parcel at Sift by customOrderNo. */
    public function resync(Request $request, Order $order): JsonResponse
    {
        try {
            $shipment = $this->service($request)->resync($order, $request->user());
        } catch (SiftException $e) {
            return $this->fail($e, $order);
        }

        return response()->json([
            'message' => $shipment ? 'Colis Sift resynchronisé (n° '.($shipment->tracking_number ?: $shipment->parcel_id).').' : 'Aucun colis Sift trouvé pour cette commande.',
            'data' => $this->orderPayload($order),
        ], $shipment ? 200 : 404);
    }

    /** GET /api/sift/orders/{order}/waybill?format= — « Télécharger l'étiquette Sift » (PDF proxied, key stays server side). */
    public function waybill(Request $request, Order $order): Response
    {
        $format = $request->validate(['format' => ['sometimes', 'nullable', Rule::in(array_keys(SiftSetting::WAYBILL_FORMATS))]])['format'] ?? null;
        $shipment = $this->shipmentOf($order, $request);
        try {
            $pdf = $this->service($request)->waybill($shipment, $format, $request->user());
        } catch (SiftException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $name = 'sift-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($shipment->tracking_number ?: $shipment->parcel_id)).'-'.($format ?: 'label').'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** POST /api/sift/labels {order_ids, format} — « Étiquettes Sift » (bulk, gated by sift_bulk_enabled). */
    public function labels(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'order_ids.*' => ['integer'],
            'format' => ['sometimes', 'nullable', Rule::in(array_keys(SiftSetting::WAYBILL_FORMATS))],
        ], ['order_ids.required' => 'Sélectionnez au moins une commande.', 'order_ids.max' => 'Maximum '.self::MAX_BULK.' commandes.']);
        $ids = array_values(array_unique($data['order_ids']));
        if (count($ids) > 1 && ! SiftShipmentService::bulkEnabled()) {
            return response()->json(['message' => 'Actions groupées Sift désactivées tant que le cycle complet n’est pas validé (Intégrations → Transporteurs → Sift.ma).'], 422);
        }
        $companyId = $request->user()->resolveCompanyId();
        $rows = app(SiftCarrier::class)->labels($companyId, Order::query()->whereIn('id', $ids)->get(['id']), $data['format'] ?? null);
        if (! $rows) {
            return response()->json(['message' => 'Aucune des commandes sélectionnées n’a de colis Sift.', 'labels' => []], 422);
        }
        $skipped = count($ids) - count($rows);

        return response()->json([
            'message' => count($rows).' étiquette(s) Sift prête(s).'.($skipped > 0 ? " {$skipped} commande(s) sans colis Sift ignorée(s)." : ''),
            'labels' => array_map(fn ($r) => $r + ['carrier' => 'sift', 'carrier_label' => 'Sift'], $rows),
        ]);
    }
}
