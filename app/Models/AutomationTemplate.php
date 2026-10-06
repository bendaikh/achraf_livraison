<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AutomationTemplate extends Model
{
    protected $fillable = [
        'company_id', 'slug', 'name', 'description', 'category', 'trigger_type',
        'trigger_config', 'definition', 'integrations', 'is_active', 'position',
    ];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'definition' => 'array',
            'integrations' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** Global library + templates owned by the company. */
    public function scopeAvailableFor(Builder $q, int $companyId): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('company_id')->orWhere('company_id', $companyId));
    }
}
