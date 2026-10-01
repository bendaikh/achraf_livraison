<?php

namespace App\Support;

/**
 * Backend-owned reference lists exposed to the SPA through /api/meta.
 * The frontend never hard-codes these: it always reads them from the API.
 * Delivery statuses themselves are NOT here — they live in the delivery_statuses table.
 */
class Catalog
{
    /** Status categories used to compute statistics independently of status names. */
    public const STATUS_CATEGORIES = [
        'avant_livraison' => 'Avant livraison',
        'en_livraison' => 'En livraison',
        'succes' => 'Succès',
        'injoignable' => 'Pas de réponse / injoignable',
        'echec' => 'Échec',
        'report' => 'Report',
        'retour' => 'Retour',
        'annulation' => 'Annulation',
    ];

    /** Categories meaning the order has left for delivery ("sorties en livraison"). */
    public const OUT_FOR_DELIVERY_CATEGORIES = ['en_livraison', 'succes', 'injoignable', 'echec', 'report', 'retour'];

    /** Call-center confirmation step (before the delivery workflow). */
    public const CONFIRMATION_STATUSES = [
        'a_confirmer' => ['label' => 'À confirmer', 'color' => '#f59e0b'],
        'confirmee' => ['label' => 'Confirmée', 'color' => '#16a34a'],
        'pas_de_reponse' => ['label' => 'Pas de réponse', 'color' => '#a855f7'],
        'reportee' => ['label' => 'Rappel reporté', 'color' => '#0ea5e9'],
        'annulee' => ['label' => 'Annulée', 'color' => '#e11d48'],
    ];

    public const MISSION_TYPES = [
        'livraison' => 'Livraison',
        'ramassage' => 'Ramassage',
        'depot_partenaire' => 'Dépôt partenaire',
        'retour' => 'Retour',
        'echange' => 'Échange',
    ];

    public const MISSION_STATUSES = [
        'a_faire' => ['label' => 'À faire', 'color' => '#f59e0b', 'open' => true],
        'en_cours' => ['label' => 'En cours', 'color' => '#2563eb', 'open' => true],
        'terminee' => ['label' => 'Terminée', 'color' => '#16a34a', 'open' => false],
        'echouee' => ['label' => 'Échouée', 'color' => '#dc2626', 'open' => false],
        'annulee' => ['label' => 'Annulée', 'color' => '#64748b', 'open' => false],
    ];

    public const PAYMENT_METHODS = [
        'cod' => 'À la livraison (COD)',
        'paye' => 'Déjà payé',
    ];

    public static function openMissionStatuses(): array
    {
        return array_keys(array_filter(self::MISSION_STATUSES, fn ($s) => $s['open']));
    }

    public static function toOptions(array $list): array
    {
        $out = [];
        foreach ($list as $value => $item) {
            $out[] = is_array($item) ? ['value' => $value] + $item : ['value' => $value, 'label' => $item];
        }

        return $out;
    }
}
