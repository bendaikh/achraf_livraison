<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Order city (normalised key) => Ozon city id, per company. source: auto | manual. */
class OzonCityMapping extends Model
{
    protected $fillable = ['company_id', 'city_key', 'city_label', 'ozon_city_id', 'source', 'updated_by'];

    protected function casts(): array
    {
        return ['ozon_city_id' => 'integer'];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(OzonCity::class, 'ozon_city_id', 'ozon_id');
    }
}
