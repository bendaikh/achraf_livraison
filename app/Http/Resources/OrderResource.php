<?php

namespace App\Http\Resources;

use App\Models\ClientBlock;
use App\Models\ConfirmationStatus;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Catalog\CatalogLookup;
use App\Services\Catalog\OrderLines;
use App\Services\OrderWorkflow;
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
            'shipping_price' => $this->shipping_price !== null ? (float) $this->shipping_price : null,
            'currency' => $this->currency ?: 'MAD',
            'payment_method' => $this->paymentMethod(),
            'financial_status' => $this->financial_status,
            'confirmation_status' => $this->confirmation_status,
            'is_confirmed' => $this->isConfirmed(),
            'confirmation_channel' => $this->confirmation_channel,
            'discount_total' => (float) $this->discount_total,
            'confirmation_status_label' => ConfirmationStatus::labelFor($this->confirmation_status),
            'confirmation_status_color' => ConfirmationStatus::colorFor($this->confirmation_status),
            'delivery_status_id' => $status?->id,
            'delivery_status_code' => $this->delivery_status,
            // Includes inactive statuses so existing orders always display correctly.
            'delivery_status' => $status ? new DeliveryStatusResource($status) : null,
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? ['id' => $this->driver->id, 'name' => $this->driver->name] : null),
            'assigned_by_name' => $this->whenLoaded('assignedByUser', fn () => $this->assignedByUser?->name),
            'carrier' => $this->carrier,
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? ['id' => $this->assignedUser->id, 'name' => $this->assignedUser->name] : null),
            // Speedaf waybill (null when never sent / cancelled), only when the relation is loaded.
            'shipment' => $this->when($this->relationLoaded('speedafShipments'), fn () => app(CarrierRegistry::class)->shipmentFor($this->resource)),
            'speedaf' => $this->when($this->relationLoaded('speedafShipments'), fn () => $this->currentSpeedafShipment()?->toSummary()),
            'speedaf_history' => $this->when($this->relationLoaded('speedafShipments') && $this->relationLoaded('histories'), fn () => $this->speedafShipments->map(fn ($s) => $s->toSummary() + ['tracks' => $s->tracks ?? []])->values()),
            'source' => $this->sourceLabel(),
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
            'timeline' => $this->when($this->relationLoaded('histories'), fn () => $this->confirmation_history ?? []),
            'allowed_status_ids' => $this->when($this->relationLoaded('histories'), fn () => app(OrderWorkflow::class)->allowedStatusIds($this->resource)),
        ];
    }
}
