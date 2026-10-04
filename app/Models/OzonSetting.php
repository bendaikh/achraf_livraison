<?php

namespace App\Models;

use App\Services\Ozon\OzonStatusMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intégrations → Transporteurs → Ozon Express, one row per company. The API key is encrypted
 * at rest, hidden from serialisation and never returned to the browser (masked hint only).
 */
class OzonSetting extends Model
{
    /** Parcel types that may get a specific parcel-stock value. */
    public const PARCEL_TYPES = [
        'livraison' => 'Commande (livraison)',
        'echange' => 'Échange (module Retours & échanges)',
    ];

    protected $fillable = [
        'company_id', 'enabled', 'customer_id', 'api_key', 'default_stock', 'stock_by_type', 'default_open',
        'default_fragile', 'nature_mode', 'nature_text', 'send_products', 'send_note', 'status_mapping',
        'seen_statuses', 'auto_sync', 'last_tested_at', 'last_test_ok', 'last_test_message', 'last_synced_at',
        'last_sync_message',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_key' => 'encrypted',
            'default_stock' => 'integer',
            'stock_by_type' => 'array',
            'default_open' => 'boolean',
            'default_fragile' => 'boolean',
            'send_products' => 'boolean',
            'send_note' => 'boolean',
            'status_mapping' => 'array',
            'seen_statuses' => 'array',
            'auto_sync' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function forCompany(int $companyId): self
    {
        return static::query()->firstOrCreate(['company_id' => $companyId], [
            'status_mapping' => OzonStatusMap::DEFAULT_MAPPING,
        ]);
    }

    public function hasCredentials(): bool
    {
        return filled($this->customer_id) && filled($this->api_key);
    }

    /** French list of what is missing before parcels can be created. */
    public function missingForShipping(): array
    {
        $missing = [];
        if (! $this->enabled) {
            $missing[] = 'activer l’intégration';
        }
        if (blank($this->customer_id)) {
            $missing[] = 'ID client';
        }
        if (blank($this->api_key)) {
            $missing[] = 'clé API';
        }

        return $missing;
    }

    /** parcel-stock for a parcel type: the type override when set, otherwise the company default. */
    public function stockFor(string $type): int
    {
        $override = ($this->stock_by_type ?? [])[$type] ?? null;

        return $override === null || $override === '' ? (int) $this->default_stock : ((int) $override ? 1 : 0);
    }

    /** Effective mapping: saved mapping (or the defaults on a fresh row) + every raw status seen. */
    public function mapping(): array
    {
        $saved = is_array($this->status_mapping) ? $this->status_mapping : OzonStatusMap::DEFAULT_MAPPING;
        $out = $saved;
        foreach ((array) ($this->seen_statuses ?? []) as $raw) {
            if (! OzonStatusMap::hasExact($out, $raw)) {
                $guess = OzonStatusMap::find($out, $raw);
                $out[$raw] = $guess === false ? null : $guess;
            }
        }

        return $out;
    }

    /** Remembers a raw status so it shows up in the mapping table. */
    public function rememberStatus(?string $raw): void
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return;
        }
        $seen = (array) ($this->seen_statuses ?? []);
        foreach ($seen as $s) {
            if (OzonStatusMap::key($s) === OzonStatusMap::key($raw)) {
                return;
            }
        }
        $seen[] = mb_substr($raw, 0, 120);
        $this->forceFill(['seen_statuses' => array_values($seen)])->save();
    }
}
