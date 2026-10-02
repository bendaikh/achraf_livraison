<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paramètres → Partenaires logistiques (Ozon, Speedaf, Jumia…), scoped by company.
 * type: ramassage | depot | both ("Les deux" appears in both selectors).
 */
class LogisticsPartner extends Model
{
    public const TYPES = [
        'ramassage' => 'Ramassage',
        'depot' => 'Dépôt',
        'both' => 'Les deux',
    ];

    /** Mission type → partner category. */
    public const MISSION_TYPE_CATEGORY = [
        'ramassage' => 'ramassage',
        'depot_partenaire' => 'depot',
    ];

    protected $fillable = [
        'company_id', 'name', 'type', 'phone', 'city', 'address', 'contact_name', 'note', 'is_active', 'is_favorite',
    ];

    protected $attributes = [
        'type' => 'both',
        'is_active' => true,
        'is_favorite' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_favorite' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    public function scopeForCompany(Builder $q, int $companyId): Builder
    {
        return $q->where('company_id', $companyId);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Partners usable for a category ("ramassage" or "depot"): "both" counts for both. */
    public function scopeForCategory(Builder $q, string $category): Builder
    {
        return $q->whereIn('type', [$category, 'both']);
    }

    /** Favourites first, then alphabetical. */
    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderByDesc('is_favorite')->orderBy('name')->orderBy('id');
    }

    public function supportsCategory(string $category): bool
    {
        return $this->type === 'both' || $this->type === $category;
    }

    /** Frozen copy stored on missions: later edits of the partner never change old missions. */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'phone' => $this->phone,
            'city' => $this->city,
            'address' => $this->address,
            'contact_name' => $this->contact_name,
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
