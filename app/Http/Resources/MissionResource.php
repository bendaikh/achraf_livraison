<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'status' => $this->status,
            'order_id' => $this->order_id,
            'order_reference' => $this->whenLoaded('order', fn () => $this->order?->reference),
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? ['id' => $this->driver->id, 'name' => $this->driver->name] : null),
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'items_description' => $this->items_description,
            'quantity' => $this->quantity,
            'scheduled_date' => $this->scheduled_date?->format('Y-m-d'),
            'time_slot' => $this->time_slot,
            'cash_amount' => $this->cash_amount !== null ? (float) $this->cash_amount : null,
            'cash_direction' => $this->cash_direction,
            'note' => $this->note,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
