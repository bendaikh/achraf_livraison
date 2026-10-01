<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'delivery_status_id' => $this->delivery_status_id,
            // Snapshot values: what the status was called at the time of the change.
            'status_code' => $this->status_code,
            'status_name' => $this->status_name,
            'status_color' => $this->status_color,
            'status_category' => $this->status_category,
            'from_status_name' => $this->from_status_name,
            'data' => $this->data,
            'note' => $this->note,
            'user_name' => $this->user?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
