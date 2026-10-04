<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Carriers\AdvancedCarrier;
use App\Services\Carriers\CarrierException;
use App\Services\Carriers\CarrierInterface;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Commandes → quick ship popup / bulk bar, carrier-agnostic (T11).
 * Local delivery stays on /api/local-delivery/* (T2).
 */
class CarrierController extends Controller
{
    public const MAX_BULK = 50;

    public function __construct(protected CarrierRegistry $carriers) {}

    /** GET /api/carriers — registered delivery companies + availability for the user's company. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'carriers' => $this->carriers->options($user->resolveCompanyId()),
            'can_ship' => $user->can('orders.ship'),
            'can_assign_driver' => $user->can('orders.assign_driver'),
        ]);
    }

    /** POST /api/carriers/{carrier}/ship {order_ids} */
    public function ship(Request $request, string $carrier): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'order_ids.*' => ['integer'],
            'options' => ['sometimes', 'array'],
            'options.open' => ['sometimes', 'boolean'],
            'options.fragile' => ['sometimes', 'boolean'],
        ], ['order_ids.max' => 'Maximum '.self::MAX_BULK.' commandes par envoi.', 'order_ids.required' => 'Sélectionnez au moins une commande.']);

        $impl = $this->carriers->get($carrier);
        if (! $impl) {
            return response()->json(['message' => 'Société de livraison inconnue.'], 404);
        }
        $companyId = $request->user()->resolveCompanyId();
        if ($reason = $impl->unavailableReason($companyId)) {
            return response()->json(['message' => $reason], 422);
        }
        if ($blocked = $this->bulkBlocked($impl, $companyId, $data['order_ids'])) {
            return response()->json(['message' => $blocked], 422);
        }
        $orders = Order::query()->whereIn('id', $data['order_ids'])->with('deliveryStatus')->get();
        if ($orders->isEmpty()) {
            return response()->json(['message' => 'Aucune commande trouvée.'], 404);
        }

        // One external parcel at a time: refuse orders already shipped with another carrier.
        $results = [];
        $toShip = [];
        foreach ($orders as $order) {
            $existing = $this->carriers->shipmentFor($order);
            if ($existing && $existing['carrier'] !== $impl->key()) {
                $results[] = ['order_id' => $order->id, 'reference' => $order->reference(), 'success' => false, 'tracking' => null,
                    'message' => "Déjà envoyée à {$existing['carrier_label']} (n° {$existing['tracking']})."];
            } else {
                $toShip[] = $order;
            }
        }
        $shipped = $impl instanceof AdvancedCarrier
            ? $impl->shipWithOptions($companyId, $toShip, $request->user(), (array) ($data['options'] ?? []))
            : $impl->ship($companyId, $toShip, $request->user());
        $results = array_merge($results, $shipped);

        $ok = count(array_filter($results, fn ($r) => $r['success']));
        $failed = count($results) - $ok;
        $message = $ok ? "{$ok} commande(s) envoyée(s) à {$impl->label()}." : "Aucune commande envoyée à {$impl->label()}.";
        if ($failed) {
            $message .= " {$failed} échec(s).";
        }
        if (count($results) === 1 && ! $results[0]['success']) {
            $message = $results[0]['message'];
        }

        $single = count($data['order_ids']) === 1 && $orders->count() === 1
            ? (new OrderResource($orders->first()->fresh()->load(['deliveryStatus', 'driver', 'assignedUser', 'speedafShipments', 'ozonShipments.deliveryNote'])))->resolve()
            : null;

        return response()->json(['message' => $message, 'sent' => $ok, 'failed' => $failed, 'results' => $results, 'data' => $single], $ok === 0 ? 422 : 200);
    }

    /**
     * POST /api/carriers/{carrier}/preview {order_ids, options} — validation popup (carriers
     * implementing AdvancedCarrier): what will be sent, blocking errors, existing parcel.
     */
    public function preview(Request $request, string $carrier): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'order_ids.*' => ['integer'],
            'options' => ['sometimes', 'array'],
        ]);
        $impl = $this->carriers->get($carrier);
        if (! $impl instanceof AdvancedCarrier) {
            return response()->json(['message' => 'Pas d’aperçu pour cette société de livraison.'], 404);
        }
        $companyId = $request->user()->resolveCompanyId();
        $orders = Order::query()->whereIn('id', $data['order_ids'])->with('deliveryStatus')->get();
        $rows = [];
        foreach ($impl->preview($companyId, $orders, (array) ($data['options'] ?? [])) as $row) {
            $other = $this->carriers->shipmentFor($orders->firstWhere('id', $row['order_id']));
            if ($other && $other['carrier'] !== $impl->key()) {
                $row['errors'][] = "Déjà envoyée à {$other['carrier_label']} (n° {$other['tracking']}).";
                $row['can_send'] = false;
            }
            $rows[] = $row;
        }

        return response()->json([
            'rows' => $rows,
            'unavailable' => $impl->unavailableReason($companyId),
            'bulk_blocked' => $this->bulkBlocked($impl, $companyId, $data['order_ids']),
        ]);
    }

    /** Refuses multi-order sends while the carrier keeps its bulk actions disabled. */
    protected function bulkBlocked(CarrierInterface $impl, int $companyId, array $ids): ?string
    {
        if (count(array_unique($ids)) < 2 || ! $impl instanceof AdvancedCarrier) {
            return null;
        }
        $caps = $impl->capabilities($companyId);

        return ($caps['bulk_enabled'] ?? true) ? null : ($caps['bulk_reason'] ?? 'Actions groupées désactivées.');
    }

    /** POST /api/carriers/labels {order_ids} — label links for every carrier of the selection. */
    public function labels(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'order_ids.*' => ['integer'],
        ]);
        $orders = Order::query()->whereIn('id', $data['order_ids'])->get();
        $companyId = $request->user()->resolveCompanyId();
        $rows = [];
        $errors = [];
        foreach ($this->carriers->all() as $carrier) {
            try {
                foreach ($carrier->labels($companyId, $orders) as $row) {
                    $rows[] = $row + ['carrier' => $carrier->key(), 'carrier_label' => $carrier->label()];
                }
            } catch (CarrierException $e) {
                $errors[] = $carrier->label().' : '.$e->getMessage();
            }
        }
        if (! $rows) {
            return response()->json(['message' => $errors ? implode(' ', $errors) : 'Aucune des commandes sélectionnées n’a été envoyée à une société de livraison.', 'labels' => []], 422);
        }
        $skipped = count(array_unique($data['order_ids'])) - count($rows);

        return response()->json([
            'message' => count($rows).' étiquette(s) prête(s).'.($skipped > 0 ? " {$skipped} commande(s) sans colis ignorée(s)." : '').($errors ? ' '.implode(' ', $errors) : ''),
            'labels' => $rows,
        ]);
    }
}
