<?php

namespace App\Http\Resources;

use App\Models\LogisticsPartner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogisticsPartnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => LogisticsPartner::TYPES[$this->type] ?? $this->type,
            'phone' => $this->phone,
            'city' => $this->city,
            'address' => $this->address,
            'contact_name' => $this->contact_name,
            'note' => $this->note,
            'is_active' => (bool) $this->is_active,
            'is_favorite' => (bool) $this->is_favorite,
            'missions_count' => $this->whenCounted('missions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
