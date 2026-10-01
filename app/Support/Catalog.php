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

    /*
    | Category groups used by the local delivery workflow (Affectation, Mes missions, Livreurs).
    | Statuses are configurable; only their *categories* carry behaviour.
    */

    /** With a driver, orders in these categories are still "chez le livreur". */
    public const DRIVER_ACTIVE_CATEGORIES = ['avant_livraison', 'en_livraison', 'report'];

    /** Confirmed orders in these categories (or without status) can be (re)assigned. */
    public const ASSIGNABLE_CATEGORIES = ['avant_livraison', 'injoignable', 'echec'];

    /** "À retraiter" in the assignment screen. */
    public const RETRY_CATEGORIES = ['injoignable', 'echec'];

    /** Finished attempts shown in the driver history. */
    public const DRIVER_HISTORY_CATEGORIES = ['succes', 'injoignable', 'echec', 'retour', 'annulation'];

    /**
     * Driver actions (Mes missions) → status category. The exact status used can be chosen in
     * Paramètres (setting "driver_action_status_ids"); otherwise the first active status of the category.
     */
    public const DRIVER_ACTIONS = [
        'take' => ['label' => 'Prise en charge', 'category' => 'en_livraison'],
        'deliver' => ['label' => 'Livrée', 'category' => 'succes'],
        'postpone' => ['label' => 'Reporter', 'category' => 'report'],
        'no_answer' => ['label' => 'Pas de réponse', 'category' => 'injoignable'],
        'fail' => ['label' => 'Échouée', 'category' => 'echec'],
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

    /** Extra information a status can require when an order enters it (configurable per status). */
    public const REQUIRED_FIELDS = [
        'postponed_at' => 'Date et heure de report',
        'reason' => 'Motif',
        'collected_amount' => 'Montant encaissé',
        'note' => 'Commentaire',
    ];

    /** Mission types a status may generate automatically for the order's driver. */
    public const STATUS_MISSION_TYPES = ['retour', 'echange'];

    /** Stored through the Shopify-compatible orders.financial_status column ("paid" = déjà payé). */
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
