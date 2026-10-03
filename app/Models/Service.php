<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Paramètres → Équipe & rémunération → Services (Confirmation, SAV…), editable. */
class Service extends Model
{
    protected $fillable = ['company_id', 'name', 'description', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
