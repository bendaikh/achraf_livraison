<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppAppSetting extends Model
{
    protected $table = 'whatsapp_app_settings';

    protected $fillable = [
        'app_id',
        'app_secret',
        'config_id',
        'webhook_verify_token',
        'graph_api_version',
    ];

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'graph_api_version' => config('services.meta.graph_api_version', 'v21.0'),
                'webhook_verify_token' => config('services.meta.webhook_verify_token'),
            ]
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->app_id) && filled($this->app_secret);
    }
}
