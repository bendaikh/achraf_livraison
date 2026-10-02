<?php

namespace App\Models;

use App\Services\Speedaf\SpeedafStatusMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Intégrations → Speedaf, one row per company. The appCode identifies the account on every
 * API call; the secretKey (encrypted) is only used to verify the HMAC-SHA256 signature of
 * the tracking webhooks pushed by Speedaf.
 */
class SpeedafSetting extends Model
{
    public const ENVIRONMENTS = [
        'uat' => 'Test (UAT)',
        'production' => 'Production',
    ];

    public const BASE_URLS = [
        'uat' => 'https://uat-api.speedaf.com/',
        'production' => 'https://apis.speedaf.com/',
    ];

    /** Public UAT appCode / customerCode documented by Speedaf (Morocco). */
    public const UAT_SAMPLE_APP_CODE = 'MA000025';

    protected $fillable = [
        'company_id', 'enabled', 'environment', 'app_code', 'customer_code', 'secret_key', 'platform_source',
        'parcel_type', 'delivery_type', 'transport_type', 'ship_type', 'pay_method', 'goods_type', 'pickup_aging',
        'allow_open', 'default_weight', 'country_code', 'currency', 'label_type', 'label_with_logo',
        'sender_name', 'sender_mobile', 'sender_address', 'sender_province', 'sender_city', 'sender_district',
        'status_mapping', 'auto_sync', 'webhook_token', 'webhook_subscribed_at',
        'last_tested_at', 'last_test_ok', 'last_test_message', 'last_synced_at',
    ];

    protected $hidden = ['secret_key'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'secret_key' => 'encrypted',
            'allow_open' => 'boolean',
            'label_with_logo' => 'boolean',
            'auto_sync' => 'boolean',
            'pickup_aging' => 'integer',
            'label_type' => 'integer',
            'default_weight' => 'decimal:3',
            'status_mapping' => 'array',
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'webhook_subscribed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function forCompany(int $companyId): self
    {
        $setting = static::query()->firstOrCreate(['company_id' => $companyId], [
            'environment' => 'uat',
            'platform_source' => 'Lavfast Flow',
            'status_mapping' => SpeedafStatusMap::DEFAULT_MAPPING,
        ]);
        if (! $setting->webhook_token) {
            $setting->forceFill(['webhook_token' => Str::random(40)])->save();
        }

        return $setting;
    }

    public function baseUrl(): string
    {
        return self::BASE_URLS[$this->environment] ?? self::BASE_URLS['uat'];
    }

    /** Credentials needed to call the API (the secret key is only needed for webhooks). */
    public function hasCredentials(): bool
    {
        return filled($this->app_code) && filled($this->customer_code);
    }

    public function hasSender(): bool
    {
        return filled($this->sender_name) && filled($this->sender_mobile) && filled($this->sender_address) && filled($this->sender_city);
    }

    /** French list of what is missing before shipments can be created. */
    public function missingForShipping(): array
    {
        $missing = [];
        if (! $this->enabled) {
            $missing[] = 'activer l’intégration';
        }
        if (blank($this->app_code)) {
            $missing[] = 'App Code';
        }
        if (blank($this->customer_code)) {
            $missing[] = 'Code client';
        }
        if (blank($this->platform_source)) {
            $missing[] = 'Platform source';
        }
        foreach (['sender_name' => 'nom expéditeur', 'sender_mobile' => 'téléphone expéditeur', 'sender_address' => 'adresse expéditeur', 'sender_city' => 'ville expéditeur'] as $field => $label) {
            if (blank($this->{$field})) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /** Effective mapping (saved mapping on top of the defaults, keys always present). */
    public function mapping(): array
    {
        $saved = is_array($this->status_mapping) ? $this->status_mapping : [];

        // array_replace (not array_merge): codes such as "5" are integer keys in PHP.
        return array_replace(
            array_fill_keys(array_keys(SpeedafStatusMap::EVENTS), null),
            $this->status_mapping === null ? SpeedafStatusMap::DEFAULT_MAPPING : [],
            $saved
        );
    }

    public function webhookUrl(): string
    {
        return url('/speedaf/webhook/'.$this->webhook_token);
    }
}
