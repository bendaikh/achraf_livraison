<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'city' => $this->city,
            'vehicle' => $this->vehicle,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'tariffs' => $this->tariffs(),
            'stats' => $this->when(isset($this->stats), fn () => $this->stats),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
