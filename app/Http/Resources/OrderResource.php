<?php

namespace App\Http\Resources;

use App\Models\ClientBlock;
use App\Models\ConfirmationStatus;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Delivery\DeliveryModeRegistry;
use App\Services\Catalog\CatalogLookup;
use App\Services\Catalog\OrderLines;
use App\Services\OrderWorkflow;
use App\Services\Orders\OrderLifecycle;
use App\Services\Orders\OrderMotifs;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commandes payload. Keeps the simple field names used by the Commandes screens while the
 * data lives in the Shopify-compatible columns (phone, total_price, shipping_address, line_items…).
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->deliveryStatusDefinition();
        $catalog = app(CatalogLookup::class);
        $lines = $catalog->enrichOrder($this->resource);
        $firstImage = collect($lines)->pluck('image_url')->filter()->first();

        return [
            'id' => $this->id,
            'reference' => $this->reference(),
            'order_number' => $this->order_number,
            'shopify_order_id' => $this->shopify_order_id,
            'shop_name' => $this->shop?->shop_name,
            'product_name' => $this->productName(),
            // Catalog photo (variant → product → line image), null = UI placeholder.
            'product_image' => $firstImage ?: $this->productImage(),
            'line_items' => $lines,
            'items_subtotal' => OrderLines::subtotal($lines),
            'items_edited_at' => $this->items_edited_at?->toIso8601String(),
            'has_out_of_stock' => collect($lines)->contains(fn ($l) => $l['out_of_stock'] === true),
            'quantity' => $this->itemsQuantity(),
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->phone,
            'client_key' => $this->phone_key,
            // T9 — blocked client alert (Commandes, Confirmation).
            'client_blocked' => ($b = ClientBlock::activeFor($this->phone_key)) ? ['reason' => $b->reason, 'comment' => $b->comment, 'blocked_at' => $b->blocked_at?->toIso8601String(), 'blocked_by_name' => $b->blocker?->name] : null,
            'email' => $this->email,
            'city' => $this->shippingCity(),
            'address' => $this->shippingAddressLine(),
            'amount' => (float) $this->total_price,
            'amount_paid' => (float) ($this->amount_paid ?? 0),
            'amount_due' => $this->amountDue(),
            'payment_label' => $this->paymentLabel(),
            'amount_due_stale' => (bool) $this->amount_due_stale,
            'shopify_sync_status' => $this->shopify_sync_status,
            'shopify_sync_error' => $this->shopify_sync_error,
            'shopify_synced_at' => $this->shopify_synced_at?->toIso8601String(),
            'shopify' => $this->when(
                $this->shopify_order_id && $this->relationLoaded('shop'),
                fn () => [
                    'shop_domain' => $this->shop?->shop_domain,
                    'order_id' => $this->shopify_order_id,
                ],
            ),
            'shipping_price' => $this->shipping_price !== null ? (float) $this->shipping_price : null,
            'currency' => $this->currency ?: 'MAD',
            'payment_method' => $this->paymentMethod(),
            'financial_status' => $this->financial_status,
            'confirmation_status' => $this->confirmation_status,
            'is_confirmed' => $this->isConfirmed(),
            'confirmation_channel' => $this->confirmation_channel,
            'discount_total' => (float) $this->discount_total,
            'confirmation_status_label' => ConfirmationStatus::labelFor($this->confirmation_status, $this->company_id),
            'confirmation_status_color' => ConfirmationStatus::colorFor($this->confirmation_status, $this->company_id),
            'payment_indicator' => $this->paymentCollectIndicator(),
            'delivery_status_id' => $status?->id,
            'delivery_status_code' => $this->delivery_status,
            // Includes inactive statuses so existing orders always display correctly.
            'delivery_status' => $status ? new DeliveryStatusResource($status) : null,
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? ['id' => $this->driver->id, 'name' => $this->driver->name, 'phone' => $this->driver->phone] : null),
            'delivery_mode' => app(DeliveryModeRegistry::class)->forOrder($this->resource),
            'assigned_by_name' => $this->whenLoaded('assignedByUser', fn () => $this->assignedByUser?->name),
            'carrier' => $this->carrier,
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? ['id' => $this->assignedUser->id, 'name' => $this->assignedUser->name] : null),
            // Speedaf waybill (null when never sent / cancelled), only when the relation is loaded.
            'shipment' => $this->when($this->relationLoaded('speedafShipments') || $this->relationLoaded('ozonShipments') || $this->relationLoaded('siftShipments'), fn () => app(CarrierRegistry::class)->shipmentFor($this->resource)),
            'ozon' => $this->when($this->relationLoaded('ozonShipments'), fn () => $this->currentOzonShipment()?->toSummary()),
            'sift' => $this->when($this->relationLoaded('siftShipments'), fn () => $this->currentSiftShipment()?->toSummary()),
            'sift_shipments' => $this->when($this->relationLoaded('siftShipments') && $this->relationLoaded('histories'), fn () => $this->siftShipments->map->toSummary()->values()),
            'ozon_shipments' => $this->when($this->relationLoaded('ozonShipments') && $this->relationLoaded('histories'), fn () => $this->ozonShipments->map->toSummary()->values()),
            'speedaf' => $this->when($this->relationLoaded('speedafShipments'), fn () => $this->currentSpeedafShipment()?->toSummary()),
            'speedaf_history' => $this->when($this->relationLoaded('speedafShipments') && $this->relationLoaded('histories'), fn () => $this->speedafShipments->map(fn ($s) => $s->toSummary() + ['tracks' => $s->tracks ?? []])->values()),
            'source' => $this->sourceLabel(),
            'lifecycle_status' => $this->status,
            'flow_state' => $this->flow_state,
            'is_draft' => $this->flow_state === 'draft',
            'creation_key' => $this->creation_key,
            'flow_notice' => $this->flow_notice,
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdByUser', fn () => $this->createdByUser?->name),
            'commercial_user_id' => $this->commercial_user_id,
            'commercial' => $this->whenLoaded('commercialUser', fn () => $this->commercialUser ? ['id' => $this->commercialUser->id, 'name' => $this->commercialUser->name] : null),
            'extra_fees' => $this->extra_fees ?? [],
            'discount_kind' => $this->discount_kind,
            'discount_value' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'cancel_reason_label' => $this->cancel_reason ? OrderMotifs::label($this->cancel_reason) : null,
            'cancel_comment' => $this->cancel_comment,
            'shopify_admin_url' => $this->shopify_order_id && $this->shop?->shop_domain
                ? 'https://'.$this->shop->shop_domain.'/admin/orders/'.$this->shopify_order_id
                : null,
            ...$this->lifecycleFlags($request),
            'note' => $this->note,
            'internal_note' => $this->internal_note,
            'status_reason' => $this->delivery_failure_reason ?? $this->cancellation_reason,
            'postponed_at' => $this->delivery_postponed_until?->format('Y-m-d H:i'),
            'recall_at' => $this->postponed_until?->format('Y-m-d H:i'),
            'collected_amount' => $this->amount_collected !== null ? (float) $this->amount_collected : null,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'shopify_created_at' => $this->shopify_created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'missions' => MissionResource::collection($this->whenLoaded('missions')),
            'histories' => OrderStatusHistoryResource::collection($this->whenLoaded('histories')),
            'fulfillments' => $this->whenLoaded('fulfillments', fn () => $this->fulfillments->map(fn ($f) => [
                'id' => $f->id,
                'status' => $f->status,
                'tracking_number' => $f->tracking_number,
                'tracking_company' => $f->tracking_company,
                'tracking_url' => $f->tracking_url,
                'source' => $f->source,
            ])->values()),
            'timeline' => $this->when($this->relationLoaded('histories'), fn () => $this->confirmation_history ?? []),
            'allowed_status_ids' => $this->when($this->relationLoaded('histories'), fn () => app(OrderWorkflow::class)->allowedStatusIds($this->resource)),
        ];
    }

    /** @return array{can_delete_draft: bool, can_cancel: bool, cancel_blockers: list<string>, delete_blockers: list<string>} */
    private function lifecycleFlags(Request $request): array
    {
        return app(OrderLifecycle::class)->flags($this->resource, $request->user());
    }
}
