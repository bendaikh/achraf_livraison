<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    public const DEFAULTS = [
        'company_name' => "Lav'Fast Flow",
        'confirmation_alert_hours' => 24,
        'default_tariffs' => [
            'livraison' => 0, 'ramassage' => 0, 'depot_partenaire' => 0, 'retour' => 0, 'echange' => 0,
        ],
    ];

    /** Company default driver tariffs (prefill new drivers). @return array<string, float> */
    public static function defaultTariffs(): array
    {
        $value = (array) static::getValue('default_tariffs', static::DEFAULTS['default_tariffs']);
        $out = [];
        foreach (array_keys(static::DEFAULTS['default_tariffs']) as $type) {
            $out[$type] = (float) ($value[$type] ?? 0);
        }

        return $out;
    }

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
