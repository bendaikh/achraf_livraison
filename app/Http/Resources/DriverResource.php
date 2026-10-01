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
            // Login e-mail of the driver's own account (role livreur).
            'email' => $this->user?->email,
            'user_id' => $this->user_id,
            'has_account' => (bool) $this->user_id,
            'city' => $this->city,
            'vehicle' => $this->vehicle,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'tariffs' => $this->tariffs(),
            'stats' => $this->when($this->getAttribute('stats') !== null, fn () => $this->getAttribute('stats')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
