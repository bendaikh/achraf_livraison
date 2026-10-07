<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientVehicle extends Model
{
    protected $fillable = [
        'company_id', 'phone_key', 'brand', 'model', 'generation', 'phase',
        'year', 'body_type', 'plate', 'is_primary', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'phone_key' => $this->phone_key,
            'brand' => $this->brand,
            'model' => $this->model,
            'generation' => $this->generation,
            'phase' => $this->phase,
            'year' => $this->year,
            'body_type' => $this->body_type,
            'plate' => $this->plate,
            'is_primary' => (bool) $this->is_primary,
        ];
    }
}
