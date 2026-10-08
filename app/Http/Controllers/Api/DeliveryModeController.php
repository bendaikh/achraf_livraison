<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Delivery\DeliveryModeRegistry;
use App\Services\Delivery\DeliveryPrecheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared delivery-mode list (local + every registered carrier) and the local pre-check
 * used by the bulk bar, the per-row popup and the fiche commande.
 */
class DeliveryModeController extends Controller
{
    public function __construct(
        protected DeliveryModeRegistry $modes,
        protected CarrierRegistry $carriers,
        protected DeliveryPrecheck $precheck,
    ) {}

    /** GET /api/delivery-modes */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canShip = $user->can('orders.ship');
        $canAssignDriver = $user->can('orders.assign_driver');

        return response()->json([
            'modes' => $this->modes->modes($user->resolveCompanyId(), $canShip, $canAssignDriver),
            'can_ship' => $canShip,
            'can_assign_driver' => $canAssignDriver,
            'can_assign_agent' => $user->can('orders.assign_agent'),
            // Task D will turn this on. Until then the bar keeps the slot hidden.
            'can_cancel' => false,
            'max_bulk' => CarrierController::MAX_BULK,
            'user_id' => $user->id,
            'company_id' => $user->resolveCompanyId(),
        ]);
    }

    /**
     * POST /api/delivery-modes/{mode}/check {order_ids}
     * Local only: no carrier HTTP. Same size limit as ship().
     */
    public function check(Request $request, string $mode): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.CarrierController::MAX_BULK],
            'order_ids.*' => ['integer'],
        ], [
            'order_ids.max' => 'Maximum '.CarrierController::MAX_BULK.' commandes par envoi.',
            'order_ids.required' => 'Sélectionnez au moins une commande.',
        ]);

        $impl = $this->carriers->get($mode);
        if (! $impl) {
            return response()->json(['message' => 'Société de livraison inconnue.'], 404);
        }
        $companyId = $request->user()->resolveCompanyId();
        $ids = array_values(array_unique(array_map('intval', $data['order_ids'])));
        $orders = Order::query()->where('company_id', $companyId)->whereIn('id', $ids)->with(['deliveryStatus', 'driver'])->get()->keyBy('id');
        $rows = $this->precheck->rows($impl, $companyId, $orders, $ids);
        $ready = count(array_filter($rows, fn ($r) => $r['can_send']));

        return response()->json([
            'selected' => count($rows),
            'ready' => $ready,
            'problems' => count($rows) - $ready,
            'rows' => $rows,
            'unavailable' => $impl->unavailableReason($companyId),
            'bulk_blocked' => $this->bulkBlocked($impl, $companyId, $ids),
            'max_bulk' => CarrierController::MAX_BULK,
        ]);
    }

    protected function bulkBlocked($impl, int $companyId, array $ids): ?string
    {
        if (count($ids) < 2 || ! $impl instanceof \App\Services\Carriers\AdvancedCarrier) {
            return null;
        }
        $caps = $impl->capabilities($companyId);

        return ($caps['bulk_enabled'] ?? true) ? null : ($caps['bulk_reason'] ?? 'Actions groupées désactivées.');
    }
}
