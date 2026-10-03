<?php

/*
| Page « Centre » (T3) — card registry. Order = display order. Each card:
|  title, description, icon (lucide-react name), color, to (SPA link), counter (method of
|  App\Services\CentreService, always computed from real data), permission (optional ability).
| Adding a card = adding an entry + a counter method; no change in the React page.
| A company can hide cards with the setting "centre_hidden_cards" (list of keys).
*/
return [
    'cards' => [
        'confirmation' => [
            'title' => 'Confirmation',
            'description' => 'Commandes à confirmer avec les clients.',
            'icon' => 'PhoneCall', 'color' => '#2563eb',
            'to' => '/confirmation',
            'counter' => 'toConfirm',
        ],
        'traitement' => [
            'title' => 'Centre de traitement',
            'description' => 'Commandes confirmées en attente d’action ou de préparation.',
            'icon' => 'ClipboardCheck', 'color' => '#0891b2',
            'to' => '/a-attribuer',
            'counter' => 'toProcess',
        ],
        'expedition' => [
            'title' => 'Centre d’expédition',
            'description' => 'Colis envoyés ou en attente chez les sociétés de livraison.',
            'icon' => 'Truck', 'color' => '#ea580c',
            'to' => '/commandes?speedaf=1',
            'counter' => 'atCarriers',
        ],
        'livraison' => [
            'title' => 'Centre de livraison',
            'description' => 'Livraisons locales en cours et mises à jour des livreurs.',
            'icon' => 'Bike', 'color' => '#16a34a',
            'to' => '/commandes?driver_id=any&status_category=avant_livraison,en_livraison,report',
            'counter' => 'localInProgress',
        ],
        'suivi' => [
            'title' => 'Centre de suivi',
            'description' => 'Pas de réponse, reportées, en retard, problèmes de livraison non résolus.',
            'icon' => 'Radar', 'color' => '#7c3aed',
            'to' => '/commandes?status_category=injoignable,report,echec',
            'counter' => 'followUp',
        ],
        'paiements' => [
            'title' => 'Paiements',
            'description' => 'Commandes livrées à rapprocher et valider (COD non clôturé).',
            'icon' => 'Wallet', 'color' => '#0d9488',
            'to' => '/cloture',
            'counter' => 'paymentsToReconcile',
        ],
        'transactions' => [
            'title' => 'Transactions',
            'description' => 'Encaissements, ajustements et paiements enregistrés (clôtures du jour).',
            'icon' => 'ArrowLeftRight', 'color' => '#475569',
            'to' => '/cloture',
            'counter' => 'transactionsToday',
        ],
        'retours' => [
            'title' => 'Retours',
            'description' => 'Commandes retournées et retours à récupérer.',
            'icon' => 'Undo2', 'color' => '#dc2626',
            'to' => '/commandes?status_category=retour',
            'counter' => 'returns',
        ],
        'echanges' => [
            'title' => 'Échanges',
            'description' => 'Échanges en cours chez les livreurs.',
            'icon' => 'Repeat', 'color' => '#c026d3',
            'to' => '/missions?type=echange',
            'counter' => 'exchanges',
        ],
        'relance' => [
            'title' => 'Relance',
            'description' => 'Colis échoués à relancer, rappeler ou réaffecter.',
            'icon' => 'RotateCcw', 'color' => '#d97706',
            'to' => '/commandes?status_category=injoignable,echec',
            'counter' => 'toRetry',
        ],
        'rupture' => [
            'title' => 'Rupture de stock',
            'description' => 'Commandes non expédiées contenant un produit indisponible.',
            'icon' => 'PackageX', 'color' => '#be123c',
            'to' => '/commandes?out_of_stock=1',
            'counter' => 'outOfStock',
        ],
    ],
];
