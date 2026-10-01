<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'product_name' => $this->product_name,
            'product_image' => $this->product_image,
            'quantity' => $this->quantity,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'city' => $this->city,
            'address' => $this->address,
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method,
            'confirmation_status' => $this->confirmation_status,
            'delivery_status_id' => $this->delivery_status_id,
            // Includes inactive statuses so existing orders always display correctly.
            'delivery_status' => $this->whenLoaded('deliveryStatus', fn () => $this->deliveryStatus ? new DeliveryStatusResource($this->deliveryStatus) : null),
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? ['id' => $this->driver->id, 'name' => $this->driver->name] : null),
            'carrier' => $this->carrier,
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? ['id' => $this->assignedUser->id, 'name' => $this->assignedUser->name] : null),
            'source' => $this->source,
            'note' => $this->note,
            'status_reason' => $this->status_reason,
            'postponed_at' => $this->postponed_at?->format('Y-m-d H:i'),
            'collected_amount' => $this->collected_amount !== null ? (float) $this->collected_amount : null,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'missions' => MissionResource::collection($this->whenLoaded('missions')),
        ];
    }
}
