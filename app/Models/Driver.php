<?php

namespace App\Models;

use App\Support\Catalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Local driver (livreur). Each driver has his own login (users.role = livreur) and
 * individual mission tariffs. Tariffs are only read when a mission is assigned: the
 * amount is then frozen in missions.driver_price (never recomputed).
 */
class Driver extends Model
{
    /** Mission type => tariff column. */
    public const TARIFF_COLUMNS = [
        'livraison' => 'tariff_livraison',
        'ramassage' => 'tariff_ramassage',
        'depot_partenaire' => 'tariff_depot_partenaire',
        'retour' => 'tariff_retour',
        'echange' => 'tariff_echange',
    ];

    protected $fillable = [
        'user_id', 'name', 'phone', 'city', 'vehicle', 'is_active', 'notes',
        'tariff_livraison', 'tariff_ramassage', 'tariff_depot_partenaire', 'tariff_retour', 'tariff_echange',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tariff_livraison' => 'decimal:2',
            'tariff_ramassage' => 'decimal:2',
            'tariff_depot_partenaire' => 'decimal:2',
            'tariff_retour' => 'decimal:2',
            'tariff_echange' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Current tariff of this driver for a mission type (used only at assignment time). */
    public function tariffFor(string $missionType): float
    {
        $column = self::TARIFF_COLUMNS[$missionType] ?? null;

        return $column ? (float) $this->{$column} : 0.0;
    }

    /** @return array<string, float> keyed by mission type */
    public function tariffs(): array
    {
        $out = [];
        foreach (self::TARIFF_COLUMNS as $type => $column) {
            $out[$type] = (float) $this->{$column};
        }

        return $out;
    }

    /** Commandes actuellement chez le livreur (statuts des catégories « actives »). */
    public function assignedOrdersCount(): int
    {
        return $this->orders()->activeWithDriver()->count();
    }

    public function inProgressOrdersCount(): int
    {
        return $this->orders()->inDeliveryCategories(['en_livraison', 'report'])->count();
    }

    public function deliveredOrdersCount(): int
    {
        return $this->orders()->inDeliveryCategories(['succes'])->count();
    }

    public function failedOrdersCount(): int
    {
        return $this->orders()->inDeliveryCategories(Catalog::RETRY_CATEGORIES)->count();
    }

    /** COD encore détenu par le livreur (livré, pas encore clôturé / remis). */
    public function codHeldAmount(): float
    {
        return (float) $this->orders()
            ->inDeliveryCategories(['succes'])
            ->whereNull('closing_id')
            ->whereNull('cod_remitted_at')
            ->sum('amount_collected');
    }
}
