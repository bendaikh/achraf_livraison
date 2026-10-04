<?php

namespace App\Models;

use App\Services\Sift\SiftClient;
use App\Services\Sift\SiftStatusMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Intégrations → Transporteurs → Sift.ma, one row per company. API key and webhook secret are
 * encrypted at rest, hidden from serialisation and never returned to the browser in clear
 * (except the webhook secret on explicit « Afficher », which the admin must paste into Sift).
 */
class SiftSetting extends Model
{
    public const WAYBILL_FORMATS = [
        'STANDARD_100x100' => 'Standard 100×100',
        'A4' => 'A4',
        'THERMAL_150x100' => 'Thermique 150×100',
        'SIFT' => 'Sift',
        'SIFT_1' => 'Sift 1',
        'SIFT_2' => 'Sift 2',
    ];

    public const WEBHOOK_EVENTS = ['parcel.status_changed', 'parcel.delivered', 'return.status_changed'];

    protected $fillable = [
        'company_id', 'enabled', 'base_url', 'api_key', 'auth_mode', 'default_allow_open', 'items_mode', 'send_note',
        'waybill_format', 'status_mapping', 'seen_statuses', 'auto_sync', 'webhook_token', 'webhook_secret',
        'webhook_remote_id', 'webhook_last_received_at', 'last_tested_at', 'last_test_ok', 'last_test_message',
        'last_synced_at', 'last_sync_message',
    ];

    protected $hidden = ['api_key', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'default_allow_open' => 'boolean',
            'send_note' => 'boolean',
            'status_mapping' => 'array',
            'seen_statuses' => 'array',
            'auto_sync' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'webhook_last_received_at' => 'datetime',
        ];
    }

    public static function forCompany(int $companyId): self
    {
        $s = static::query()->firstOrCreate(['company_id' => $companyId], [
            'base_url' => SiftClient::BASE_URL,
            'status_mapping' => SiftStatusMap::DEFAULT_MAPPING,
        ]);
        if (blank($s->webhook_token) || blank($s->webhook_secret)) {
            $s->forceFill([
                'webhook_token' => $s->webhook_token ?: Str::random(40),
                'webhook_secret' => $s->webhook_secret ?: 'whsec_'.Str::random(40),
            ])->save();
        }

        return $s;
    }

    public function hasCredentials(): bool
    {
        return filled($this->api_key);
    }

    public function missingForShipping(): array
    {
        $missing = [];
        if (! $this->enabled) {
            $missing[] = 'activer l’intégration';
        }
        if (blank($this->api_key)) {
            $missing[] = 'clé API';
        }

        return $missing;
    }

    public function webhookUrl(): string
    {
        return url('/sift/webhook/'.$this->webhook_token);
    }

    public function mapping(): array
    {
        $out = is_array($this->status_mapping) ? $this->status_mapping : SiftStatusMap::DEFAULT_MAPPING;
        foreach ((array) ($this->seen_statuses ?? []) as $raw) {
            if (! SiftStatusMap::hasExact($out, $raw)) {
                $guess = SiftStatusMap::find($out, $raw);
                $out[$raw] = $guess === false ? null : $guess;
            }
        }

        return $out;
    }

    public function rememberStatus(?string $raw): void
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return;
        }
        $seen = (array) ($this->seen_statuses ?? []);
        foreach ($seen as $s) {
            if (SiftStatusMap::key($s) === SiftStatusMap::key($raw)) {
                return;
            }
        }
        $seen[] = mb_substr($raw, 0, 120);
        $this->forceFill(['seen_statuses' => array_values($seen)])->save();
    }
}
