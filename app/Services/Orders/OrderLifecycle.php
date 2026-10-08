<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderDeletion;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\MissionService;
use App\Services\Shopify\ShopifyOrderCancelService;
use Illuminate\Validation\ValidationException;

/** Draft delete (soft) and order cancellation. Confirmation status is never reused. */
class OrderLifecycle
{
    public function __construct(
        private readonly CarrierRegistry $carriers,
        private readonly MissionService $missions,
        private readonly ShopifyOrderCancelService $shopify,
    ) {}

    /** @return array{can_delete_draft: bool, can_cancel: bool, cancel_blockers: list<string>, delete_blockers: list<string>} */
    public function flags(Order $order, ?User $user): array
    {
        $delete = $this->deleteBlockers($order);
        $cancel = $this->cancelBlockers($order);

        return [
            'can_delete_draft' => (bool) ($user && $user->can('orders.delete_draft') && $delete === []),
            'can_cancel' => (bool) ($user && $user->can('orders.cancel') && $order->status !== 'cancelled' && $order->flow_state !== 'draft' && $order->flow_state !== 'creating' && $cancel === []),
            'cancel_blockers' => $cancel,
            'delete_blockers' => $delete,
        ];
    }

    /** @return list<string> */
    public function deleteBlockers(Order $order): array
    {
        $reasons = [];
        if ($order->shopify_order_id) {
            $reasons[] = 'Commande déjà créée dans Shopify.';
        }
        if ($order->flow_state !== 'draft') {
            $reasons[] = 'Seuls les brouillons peuvent être supprimés.';
        }
        if ($order->speedafShipments()->exists() || $order->ozonShipments()->exists() || $order->siftShipments()->exists()) {
            $reasons[] = 'Un colis transporteur existe déjà.';
        }
        if ($order->missions()->exists() || $order->driver_id) {
            $reasons[] = 'Une mission ou un livreur est déjà lié.';
        }
        if ((float) $order->amount_paid > 0.009 || (float) ($order->amount_collected ?? 0) > 0.009 || in_array($order->financial_status, ['paid', 'partially_paid'], true)) {
            $reasons[] = 'Un paiement est déjà enregistré.';
        }
        if ($order->closing_id) {
            $reasons[] = 'Commande incluse dans une clôture.';
        }
        if ($order->delivered_at || $order->deliveryCategory() === 'succes') {
            $reasons[] = 'Commande déjà livrée.';
        }
        if ($order->savRequests()->exists()) {
            $reasons[] = 'Un dossier SAV existe déjà.';
        }

        return $reasons;
    }

    /** @return list<string> */
    public function cancelBlockers(Order $order): array
    {
        if ($order->status === 'cancelled') {
            return ['Commande déjà annulée.'];
        }
        if (in_array($order->flow_state, ['draft', 'creating'], true) && ! $order->shopify_order_id) {
            return ['Un brouillon se supprime, il ne s’annule pas.'];
        }
        $reasons = [];
        $shipment = $this->carriers->shipmentFor($order);
        if ($shipment) {
            $reasons[] = 'Annulez d’abord le colis chez '.($shipment['carrier_label'] ?? 'le transporteur');
        }
        if ($order->closing_id || $order->delivered_at || $order->deliveryCategory() === 'succes') {
            $reasons[] = 'Commande livrée / clôturée : utilisez un retour (SAV)';
        }
        foreach ($order->missions as $mission) {
            if (! in_array($mission->status, ['a_faire', 'annulee'], true)) {
                $reasons[] = 'Mission déjà commencée';
                break;
            }
        }

        return $reasons;
    }

    public function deleteDraft(Order $order, User $user, ?string $reason = null): void
    {
        abort_unless($user->can('orders.delete_draft'), 403);
        $blockers = $this->deleteBlockers($order);
        if ($blockers !== []) {
            throw ValidationException::withMessages(['order' => $blockers[0]]);
        }
        OrderDeletion::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'reason' => $reason,
            'snapshot' => $order->only([
                'name', 'order_number', 'customer_name', 'phone', 'email', 'source', 'total_price',
                'line_items', 'note', 'internal_note', 'created_by', 'commercial_user_id', 'creation_key', 'flow_state',
            ]),
            'created_at' => now(),
        ]);
        $order->forceFill(['deleted_at' => now()])->save();
    }

    public function cancel(Order $order, User $user, string $reason, ?string $comment, bool $refund): Order
    {
        abort_unless($user->can('orders.cancel'), 403);
        $code = OrderMotifs::code($reason);
        if (! $code) {
            throw ValidationException::withMessages(['reason' => 'Motif d’annulation requis.']);
        }
        $blockers = $this->cancelBlockers($order);
        if ($blockers !== []) {
            throw ValidationException::withMessages(['order' => $blockers[0]]);
        }
        $paid = in_array($order->financial_status, ['paid', 'partially_paid'], true) || (float) $order->amount_paid > 0.009;
        if ($refund && ! $paid) {
            throw ValidationException::withMessages(['refund' => 'Remboursement disponible uniquement pour une commande payée.']);
        }
        if ($refund && ! $user->isSuperAdmin() && $user->role !== User::ROLE_ADMIN) {
            abort(403, 'Seul un administrateur peut rembourser le client sur Shopify.');
        }

        $confirmation = $order->confirmation_status;
        $before = $this->situation($order);
        $pending = false;
        if ($order->shopify_order_id) {
            $pending = $this->shopify->cancel($order, $user, $code, $comment, $refund && $paid)['pending'];
        }
        foreach ($order->missions as $mission) {
            if ($mission->status === 'a_faire') {
                $this->missions->changeStatus($mission, 'annulee', 'Commande annulée');
            }
        }

        $order->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancel_reason' => $code,
            'cancel_comment' => $comment,
            'confirmation_status' => $confirmation,
            'total_outstanding' => 0,
            'shopify_sync_status' => $order->shopify_order_id ? ($pending ? 'pending' : 'synced') : $order->shopify_sync_status,
        ])->save();

        $fresh = $order->fresh();
        $after = $this->situation($fresh);
        $label = 'Commande annulée par '.$user->name.' — '.OrderMotifs::label($code).($comment ? ' — '.$comment : '');
        $fresh->appendHistory('order_cancelled', $label, $user, [
            'reason' => OrderMotifs::label($code),
            'comment' => $comment,
            'before' => $before,
            'after' => $after,
        ], $comment);
        $fresh->save();
        OrderStatusHistory::create([
            'order_id' => $fresh->id,
            'kind' => 'commande',
            'status_code' => 'order_cancelled',
            'status_name' => 'Commande annulée',
            'status_color' => '#dc2626',
            'note' => $label,
            'data' => [
                'user' => $user->name,
                'at' => now()->toIso8601String(),
                'reason' => OrderMotifs::label($code),
                'comment' => $comment,
                'before' => $before,
                'after' => $after,
            ],
            'user_id' => $user->id,
        ]);

        return $fresh->fresh();
    }

    /** @return array<string, mixed> */
    private function situation(Order $order): array
    {
        return [
            'status' => $order->status,
            'confirmation' => $order->confirmation_status,
            'delivery' => $order->delivery_status,
            'shipment' => $this->carriers->shipmentFor($order)['tracking'] ?? null,
            'amount_due' => $order->amountDue(),
        ];
    }
}
