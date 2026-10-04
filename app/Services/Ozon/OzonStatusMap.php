<?php

namespace App\Services\Ozon;

use Illuminate\Support\Str;

/**
 * Raw Ozon Express statuses (TRACKING.LAST_TRACKING.STATUT, French labels) and their default
 * mapping onto Lav'Fast Flow delivery statuses (delivery_statuses.code, null = keep the current
 * status). The exact list is not published by Ozon: these are the labels seen in public client
 * code (see docs/integration-ozon.md). Any other status received is remembered and added to
 * the editable table (Paramètres → Transporteurs → Ozon Express → Mapping des statuts).
 */
class OzonStatusMap
{
    public const DEFAULT_MAPPING = [
        'Nouveau Colis' => null,
        'En attente de ramassage' => null,
        'Ramassé' => 'in_progress',
        'Reçu' => 'in_progress',
        'En transit' => 'in_progress',
        'En cours de livraison' => 'in_progress',
        'Livré' => 'delivered',
        'Pas de réponse' => 'no_answer',
        'Reporté' => 'postponed',
        'Refusé' => 'failed',
        'Annulé' => 'cancelled',
        'Retourné' => 'returned',
    ];

    /** Normalised comparison key: lower case, no accents, single spaces. */
    public static function key(?string $raw): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii((string) $raw))));
    }

    /** True when the mapping has an entry for exactly this raw status (normalised). */
    public static function hasExact(array $mapping, ?string $raw): bool
    {
        $key = self::key($raw);
        foreach (array_keys($mapping) as $label) {
            if (self::key((string) $label) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mapped delivery status code for a raw status. false = status unknown to the mapping,
     * null = known but deliberately not mapped.
     */
    public static function find(array $mapping, ?string $raw): string|false|null
    {
        $key = self::key($raw);
        if ($key === '') {
            return false;
        }
        foreach ($mapping as $label => $code) {
            if (self::key((string) $label) === $key) {
                return $code ?: null;
            }
        }
        // Common variants ("En Livraison", "Colis Reçu", "Retour…") fall back on a known family.
        foreach (self::FAMILIES as $needle => $label) {
            if (str_contains($key, $needle)) {
                foreach ($mapping as $l => $code) {
                    if (self::key((string) $l) === self::key($label)) {
                        return $code ?: null;
                    }
                }

                return false;
            }
        }

        return false;
    }

    /** substring of the normalised raw status => default label it behaves like. */
    protected const FAMILIES = [
        'attente' => 'En attente de ramassage',
        'retour' => 'Retourné',
        'refus' => 'Refusé',
        'annul' => 'Annulé',
        'echec' => 'Refusé',
        'non livre' => 'Refusé',
        'en livraison' => 'En cours de livraison',
        'distribution' => 'En cours de livraison',
        'livre' => 'Livré',
        'transit' => 'En transit',
        'ramass' => 'Ramassé',
        'recu' => 'Reçu',
        'injoignable' => 'Pas de réponse',
        'pas de reponse' => 'Pas de réponse',
        'report' => 'Reporté',
    ];
}
