<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\SpeedafSetting;
use App\Models\SpeedafShipment;
use App\Services\Speedaf\SpeedafException;
use App\Services\Speedaf\SpeedafShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Commandes → Speedaf actions (send, cancel, refresh tracking, print labels). */
class SpeedafOrderController extends Controller
{
    public const MAX_BULK = 50;

    protected function service(Request $request): SpeedafShipmentService
    {
        return SpeedafShipmentService::for(SpeedafSetting::forCompany($request->user()->resolveCompanyId()));
    }

    protected function orderPayload(Order $order): array
    {
        return (new OrderResource($order->fresh()->load(['deliveryStatus', 'driver', 'assignedUser', 'shop:id,shop_domain,shop_name', 'missions.driver', 'histories.user', 'speedafShipments'])))->resolve();
    }

    /** POST /api/speedaf/orders/send {order_ids: []} — one or many (multi-select). */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'order_ids.*' => ['integer'],
        ], ['order_ids.max' => 'Maximum '.self::MAX_BULK.' commandes par envoi.', 'order_ids.required' => 'Sélectionnez au moins une commande.']);

        $service = $this->service($request);
        try {
            $service->assertReady();
        } catch (SpeedafException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $orders = Order::query()->whereIn('id', $data['order_ids'])->with('deliveryStatus')->get();
        if ($orders->isEmpty()) {
            return response()->json(['message' => 'Aucune commande trouvée.'], 404);
        }
        $results = $service->createMany($orders, $request->user());
        $ok = count(array_filter($results, fn ($r) => $r['success']));
        $failed = count($results) - $ok;

        $message = $ok ? "{$ok} commande(s) envoyée(s) à Speedaf." : 'Aucune commande envoyée à Speedaf.';
        if ($failed) {
            $message .= " {$failed} échec(s).";
        }
        if (count($results) === 1 && ! $results[0]['success']) {
            $message = $results[0]['message'];
        }

        return response()->json([
            'message' => $message,
            'sent' => $ok,
            'failed' => $failed,
            'results' => $results,
            'data' => count($data['order_ids']) === 1 && $orders->count() === 1 ? $this->orderPayload($orders->first()) : null,
        ], $ok === 0 ? 422 : 200);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        try {
            $this->service($request)->cancel($order, trim((string) ($data['reason'] ?? '')) ?: 'Annulation expéditeur', $request->user());
        } catch (SpeedafException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Envoi Speedaf annulé.', 'data' => $this->orderPayload($order)]);
    }

    public function sync(Request $request, Order $order): JsonResponse
    {
        $shipment = SpeedafShipmentService::activeShipment($order);
        if (! $shipment) {
            return response()->json(['message' => 'Cette commande n’a pas d’envoi Speedaf.'], 422);
        }
        $stats = $this->service($request)->sync([$shipment]);
        if ($stats['errors']) {
            return response()->json(['message' => implode(' ', $stats['errors'])], 422);
        }
        $shipment->refresh();
        $message = $shipment->last_event_at ? 'Suivi Speedaf : '.$shipment->last_action_name : 'Aucun événement de suivi pour le moment (colis pas encore ramassé).';

        return response()->json(['message' => $message, 'data' => $this->orderPayload($order)]);
    }

    /** GET /api/speedaf/orders/{order}/label — the waybill PDF (inline). */
    public function label(Request $request, Order $order): Response
    {
        $shipment = SpeedafShipmentService::activeShipment($order);
        if (! $shipment) {
            return response()->json(['message' => 'Cette commande n’a pas d’envoi Speedaf.'], 422);
        }
        try {
            $labels = $this->service($request)->labels([$shipment]);
        } catch (SpeedafException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $label = $labels[$shipment->bill_code] ?? reset($labels);
        if (! empty($label['base64'])) {
            $pdf = base64_decode($label['base64'], true);
            if ($pdf !== false && str_starts_with($pdf, '%PDF')) {
                return response($pdf, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="speedaf-'.$shipment->bill_code.'.pdf"',
                    'Cache-Control' => 'private, no-store',
                ]);
            }
        }
        if (! empty($label['url'])) {
            return redirect()->away($label['url']);
        }

        return response()->json(['message' => 'Étiquette Speedaf indisponible.'], 422);
    }

    /** POST /api/speedaf/labels {order_ids} — label links for the selection. */
    public function labels(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'order_ids.*' => ['integer'],
        ]);
        $shipments = SpeedafShipment::query()->whereIn('order_id', $data['order_ids'])
            ->where('state', '!=', SpeedafShipment::STATE_CANCELLED)->whereNotNull('bill_code')
            ->with('order')->latest('id')->get()->unique('order_id')->values();
        if ($shipments->isEmpty()) {
            return response()->json(['message' => 'Aucune des commandes sélectionnées n’a été envoyée à Speedaf.'], 422);
        }
        try {
            $labels = $this->service($request)->labels($shipments->all());
        } catch (SpeedafException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $rows = $shipments->map(fn (SpeedafShipment $s) => [
            'order_id' => $s->order_id,
            'reference' => $s->order?->reference(),
            'bill_code' => $s->bill_code,
            'url' => $labels[$s->bill_code]['url'] ?? null,
            'pdf_url' => url('/api/speedaf/orders/'.$s->order_id.'/label'),
        ])->values();
        $skipped = count(array_unique($data['order_ids'])) - $rows->count();

        return response()->json([
            'message' => $rows->count().' étiquette(s) prête(s).'.($skipped > 0 ? " {$skipped} commande(s) sans envoi Speedaf ignorée(s)." : ''),
            'labels' => $rows,
        ]);
    }
}
