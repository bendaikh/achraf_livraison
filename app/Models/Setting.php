<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    public const DEFAULTS = [
        'company_name' => 'Lavafast Livraison',
        'confirmation_alert_hours' => 24,
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();
        if ($row) {
            return $row->value;
        }

        return $default ?? (static::DEFAULTS[$key] ?? null);
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
