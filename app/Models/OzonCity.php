<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Official Ozon Express city (GET https://api.ozonexpress.ma/cities). */
class OzonCity extends Model
{
    protected $fillable = ['ozon_id', 'ref', 'name', 'name_key', 'delivered_price', 'returned_price', 'refused_price', 'active'];

    protected function casts(): array
    {
        return [
            'ozon_id' => 'integer',
            'delivered_price' => 'decimal:2',
            'returned_price' => 'decimal:2',
            'refused_price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function toOption(): array
    {
        return [
            'id' => $this->ozon_id,
            'ref' => $this->ref,
            'name' => $this->name,
            'delivered_price' => $this->delivered_price !== null ? (float) $this->delivered_price : null,
            'returned_price' => $this->returned_price !== null ? (float) $this->returned_price : null,
            'refused_price' => $this->refused_price !== null ? (float) $this->refused_price : null,
        ];
    }
}
