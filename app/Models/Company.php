<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'timezone',
        'features',
        'campaign_settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'features' => 'array',
            'campaign_settings' => 'array',
        ];
    }

    public function timezoneOrDefault(): string
    {
        return $this->timezone ?: 'Africa/Casablanca';
    }

    /** Feature flags stored in companies.features JSON. */
    public function hasFeature(string $key, bool $default = false): bool
    {
        $features = $this->features ?? [];

        return (bool) ($features[$key] ?? $default);
    }

    public function vehiclesEnabled(): bool
    {
        return $this->hasFeature('client_vehicles', false);
    }

    /**
     * Campaign settings defaults:
     * - exclude_refused_consent (true)
     * - require_allowed_consent_for_marketing (false) — when true, only "allowed" receive marketing
     * - max_campaigns_per_client (3)
     * - max_campaigns_window_days (7)
     * - rate_limit_per_minute (30)
     * - batch_size (25)
     * - fake_sender (false) — force simulation even outside testing
     */
    public function campaignSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->campaign_settings ?? [];

        return $settings[$key] ?? $default;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function whatsappAccounts(): HasMany
    {
        return $this->hasMany(WhatsAppAccount::class);
    }

    public function logisticsPartners(): HasMany
    {
        return $this->hasMany(LogisticsPartner::class);
    }

    public static function default(): self
    {
        return static::query()->firstOrCreate(
            ['slug' => 'lavfast-flow'],
            ['name' => "Lav'Fast Flow", 'is_active' => true]
        );
    }
}
