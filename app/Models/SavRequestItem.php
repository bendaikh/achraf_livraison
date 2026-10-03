<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavRequestItem extends Model
{
    public const STATES = ['pending' => 'En attente', 'with_driver' => 'Chez le livreur', 'at_depot' => 'Au dépôt', 'delivered' => 'Remis au client', 'returned' => 'Revenu au dépôt'];

    protected $fillable = ['sav_request_id', 'direction', 'product_variant_id', 'line_key', 'title', 'variant_title', 'sku', 'image_url', 'quantity', 'unit_price', 'state'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SavRequest::class, 'sav_request_id');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id, 'direction' => $this->direction, 'title' => $this->title, 'variant_title' => $this->variant_title,
            'sku' => $this->sku, 'image_url' => $this->image_url, 'quantity' => $this->quantity,
            'unit_price' => $this->unit_price !== null ? (float) $this->unit_price : null,
            'state' => $this->state, 'state_label' => self::STATES[$this->state] ?? $this->state,
        ];
    }
}
