<?php

namespace App\Services\Speedaf;

/**
 * Speedaf tracking codes (PDF §5.2 "Delivery Tracking" and §5.3 "Problem Shipment List") and the
 * default mapping onto Lavfast delivery statuses (codes of delivery_statuses, configurable in
 * Intégrations → Speedaf). Problem sub-codes such as "IP05-02" fall back to their parent "IP05".
 */
class SpeedafStatusMap
{
    public const EVENTS = [
        '1' => 'Ramassé par Speedaf (Pick Up)',
        '2' => 'Départ du site / centre de tri',
        '3' => 'Arrivé au centre de tri (DC)',
        '4' => 'En cours de livraison',
        '5' => 'Livré',
        '-710' => 'Retour en cours',
        '730' => 'Retour réceptionné (signé)',
        'IP01' => 'Destinataire injoignable',
        'IP02' => 'Adresse incomplète',
        'IP03' => 'Adresse modifiée',
        'IP04' => 'Hors zone de livraison',
        'IP05' => 'Refusé par le destinataire',
        'IP06' => 'Livraison programmée par le destinataire',
        'IP07' => 'Mal trié / mal acheminé',
        'IP08' => 'Colis à retirer (point relais)',
        'IP09' => 'Retour demandé par l’expéditeur',
        'IP10' => 'Commande en double',
        'IP11' => 'Colis perdu',
        'IP12' => 'Colis endommagé',
        'IP13' => 'Modification du bordereau',
        'IP14' => 'Autre problème',
        'IP16' => 'Paiement anticipé de la marchandise',
        '5573' => 'Non livrable (force majeure)',
    ];

    /** Speedaf code => delivery_statuses.code (seeded statuses). null = keep the current status. */
    public const DEFAULT_MAPPING = [
        '1' => 'in_progress',
        '2' => 'in_progress',
        '3' => 'in_progress',
        '4' => 'in_progress',
        '5' => 'delivered',
        '-710' => 'returned',
        '730' => 'returned',
        'IP01' => 'no_answer',
        'IP05' => 'failed',
        'IP06' => 'postponed',
        'IP09' => 'returned',
        'IP11' => 'failed',
        '5573' => 'failed',
    ];

    /** Final Speedaf codes: polling stops once reached. */
    public const DELIVERED_CODES = ['5'];

    public const RETURNED_CODES = ['730'];

    /** Mapping key for a tracking record: the most specific known code among subAction / action. */
    public static function keyFor(?string $action, ?string $subAction = null): ?string
    {
        foreach ([$subAction, $action] as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            if (array_key_exists($code, self::EVENTS)) {
                return $code;
            }
            // "IP05-02" → "IP05"
            if (preg_match('/^(IP\d{2})/i', $code, $m) && array_key_exists(strtoupper($m[1]), self::EVENTS)) {
                return strtoupper($m[1]);
            }
        }
        $action = trim((string) $action);

        return $action !== '' ? $action : null;
    }

    public static function label(?string $code): ?string
    {
        return $code !== null ? (self::EVENTS[$code] ?? null) : null;
    }

    public static function options(): array
    {
        $out = [];
        foreach (self::EVENTS as $code => $label) {
            $out[] = ['code' => (string) $code, 'label' => $label];
        }

        return $out;
    }
}
