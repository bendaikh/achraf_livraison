<?php

namespace App\Models;

use App\Services\Ozon\OzonClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bon de livraison Ozon (add-delivery-note → add-parcel-to-delivery-note → save-delivery-note). */
class OzonDeliveryNote extends Model
{
    protected $fillable = ['company_id', 'ref', 'state', 'parcels_count', 'last_error', 'responses', 'saved_at', 'created_by'];

    protected function casts(): array
    {
        return ['responses' => 'array', 'saved_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OzonDeliveryNoteItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documentUrls(): array
    {
        return $this->ref ? OzonClient::documentUrls($this->ref) : [];
    }

    public function toSummary(bool $withItems = false): array
    {
        $out = [
            'id' => $this->id,
            'ref' => $this->ref,
            'state' => $this->state,
            'parcels_count' => (int) $this->parcels_count,
            'last_error' => $this->last_error,
            'saved_at' => $this->saved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->creator?->name,
            'documents' => $this->documentUrls(),
        ];
        if ($withItems) {
            $out['items'] = $this->items->map(fn (OzonDeliveryNoteItem $i) => [
                'order_id' => $i->order_id,
                'reference' => $i->order?->reference(),
                'tracking_number' => $i->tracking_number,
            ])->values()->all();
        }

        return $out;
    }
}
