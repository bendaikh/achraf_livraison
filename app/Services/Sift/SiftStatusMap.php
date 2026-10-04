<?php

namespace App\Services\Sift;

use Illuminate\Support\Str;

/**
 * Raw Sift.ma parcel statuses (machine codes) and their default mapping onto Lav'Fast Flow
 * delivery statuses (delivery_statuses.code, null = keep the current status).
 *
 * The code list and French labels come from Sift's own merchant web app (public JS bundle,
 * PARCEL_STATUS_OPTIONS, 04/10/2026) — see docs/integration-sift.md. Codes received that are
 * not listed are remembered and added to the editable table (Paramètres → Transporteurs →
 * Sift → Mapping des statuts).
 */
class SiftStatusMap
{
    public const LABELS = [
        'pending' => 'En attente',
        'awaiting_pickup' => 'Ramassage programmé',
        'picked_up' => 'Ramassé',
        'in_transit' => 'En transit (Livraison)',
        'yet_to_arrive' => 'Pas encore arrivé',
        'out_for_delivery' => 'En livraison',
        'processing' => 'En traitement',
        'rescheduled' => 'Reprogrammé',
        'injoignable' => 'Injoignable',
        'invalid_address' => 'Adresse invalide',
        'address_change' => 'Changement d’adresse',
        'mal_tri' => 'Mal tri',
        'damaged' => 'Endommagé',
        'delivery_exception' => 'Anomalie de livraison',
        'out_of_coverage' => 'Hors zone de livraison',
        'duplicate_order' => 'Commande en double',
        'lost' => 'Colis perdu',
        'force_majeure' => 'Force majeure',
        'advance_payment_required' => 'Paiement anticipé requis',
        'agency_pickup' => 'Retrait agence',
        'return_in_progress' => 'En transit (Retour)',
        'return_to_client' => 'Retour vers client',
        'returned' => 'Retourné',
        'return_to_stock' => 'Retour au stock',
        'delivered' => 'Livré',
        'cancelled' => 'Annulé',
        'rejected' => 'Refusé',
        'deleted' => 'Supprimé',
    ];

    public const DEFAULT_MAPPING = [
        'pending' => null,
        'awaiting_pickup' => null,
        'picked_up' => 'in_progress',
        'in_transit' => 'in_progress',
        'yet_to_arrive' => 'in_progress',
        'out_for_delivery' => 'in_progress',
        'processing' => null,
        'rescheduled' => 'postponed',
        'injoignable' => 'no_answer',
        'invalid_address' => 'failed',
        'address_change' => null,
        'mal_tri' => null,
        'damaged' => 'failed',
        'delivery_exception' => 'failed',
        'out_of_coverage' => 'failed',
        'duplicate_order' => null,
        'lost' => 'failed',
        'force_majeure' => null,
        'advance_payment_required' => null,
        'agency_pickup' => null,
        'return_in_progress' => 'returned',
        'return_to_client' => 'returned',
        'returned' => 'returned',
        'return_to_stock' => 'returned',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled',
        'rejected' => 'failed',
        'deleted' => null,
    ];

    /** Statuses in which Sift still accepts PUT /parcels/{id} edits. */
    public const EDITABLE = ['pending'];

    /** Normalised key: lower case, ascii, spaces/dashes → underscore ("Out for delivery" = out_for_delivery). */
    public static function key(?string $raw): string
    {
        $k = Str::lower(Str::ascii(trim((string) $raw)));

        return trim((string) preg_replace('/[\s\-]+/', '_', $k), '_');
    }

    public static function label(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::LABELS[self::key($raw)] ?? $raw;
    }

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

    /** Mapped delivery status code. false = unknown to the mapping, null = deliberately unmapped. */
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
        // French labels or close variants fall back on the code they stand for.
        foreach (self::LABELS as $code => $label) {
            if (self::key($label) === $key && array_key_exists($code, $mapping)) {
                return $mapping[$code] ?: null;
            }
        }

        return false;
    }
}
