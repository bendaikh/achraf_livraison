<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'name', 'phone', 'email', 'city', 'vehicle', 'is_active', 'notes',
        'tariff_livraison', 'tariff_ramassage', 'tariff_depot_partenaire', 'tariff_retour', 'tariff_echange',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'tariff_livraison' => 'decimal:2',
        'tariff_ramassage' => 'decimal:2',
        'tariff_depot_partenaire' => 'decimal:2',
        'tariff_retour' => 'decimal:2',
        'tariff_echange' => 'decimal:2',
    ];

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

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function missions()
    {
        return $this->hasMany(Mission::class);
    }
}
