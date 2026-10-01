<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'color' => $this->color,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'category' => $this->category,
            'required_fields' => $this->requiredFields(),
            'creates_mission_type' => $this->creates_mission_type,
            'transition_to_ids' => $this->whenLoaded('transitionsFrom', fn () => $this->transitionsFrom->pluck('to_status_id')->values()),
            'usage_count' => $this->when(isset($this->orders_count), fn () => (int) $this->orders_count),
            'history_count' => $this->when(isset($this->histories_count), fn () => (int) $this->histories_count),
        ];
    }
}
