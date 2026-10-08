<?php

namespace App\Services\Orders;

/** Seed list. A company setting can replace it later. */
class OrderMotifs
{
    /** @var array<string, string> */
    public const MOTIFS = [
        'client' => 'Client a annulé',
        'doublon' => 'Doublon',
        'saisie' => 'Erreur de saisie',
        'stock' => 'Rupture de stock',
        'autre' => 'Autre',
    ];

    public static function code(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (isset(self::MOTIFS[$value])) {
            return $value;
        }
        $found = array_search($value, self::MOTIFS, true);

        return $found === false ? null : (string) $found;
    }

    public static function label(string $code): string
    {
        return self::MOTIFS[$code] ?? $code;
    }
}
