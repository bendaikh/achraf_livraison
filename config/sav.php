<?php

/*
| T7 — Retours / échanges workflow. Labels, colors and transitions are configuration (generic for
| every company). "by" = who may trigger the transition: driver (Espace livreur) or admin (back-office).
| The driver can never close a request: reception at depot and closing are admin-only.
*/
return [
    'types' => [
        'retour' => ['label' => 'Retour', 'description' => 'Récupérer le produit chez le client'],
        'echange' => ['label' => 'Échange', 'description' => 'Récupérer l’ancien produit et remettre le nouveau'],
    ],

    'reasons' => [
        'Mauvaise référence', 'Produit non compatible', 'Produit défectueux', 'Client a changé d’avis',
        'Erreur de préparation', 'Mauvais produit envoyé', 'Autre',
    ],

    'statuses' => [
        'created' => ['label' => 'Demande créée', 'color' => '#64748b'],
        'to_assign' => ['label' => 'À attribuer', 'color' => '#f59e0b'],
        'assigned' => ['label' => 'Attribuée', 'color' => '#6366f1'],
        'en_route' => ['label' => 'En route', 'color' => '#2563eb'],
        'at_customer' => ['label' => 'Chez le client', 'color' => '#0891b2'],
        'no_answer' => ['label' => 'Pas de réponse', 'color' => '#ea580c'],
        'postponed' => ['label' => 'Reportée', 'color' => '#a855f7'],
        'problem' => ['label' => 'Problème', 'color' => '#dc2626'],
        'picked_up' => ['label' => 'Produit récupéré', 'color' => '#0d9488'],
        'exchanged' => ['label' => 'Échange effectué', 'color' => '#0d9488'],
        'returning' => ['label' => 'Retour au dépôt', 'color' => '#7c3aed'],
        'received' => ['label' => 'Réceptionné au dépôt', 'color' => '#16a34a'],
        'closed' => ['label' => 'Clôturée', 'color' => '#15803d'],
        'cancelled' => ['label' => 'Annulée', 'color' => '#94a3b8'],
    ],

    /* Statuses still "open" on the driver side (shown in Espace livreur → Retours & échanges). */
    'driver_open' => ['assigned', 'en_route', 'at_customer', 'no_answer', 'postponed', 'problem', 'picked_up', 'exchanged', 'returning'],

    /* action => [to, by, from[], types[] (null = all), label] */
    'actions' => [
        'en_route' => ['to' => 'en_route', 'by' => ['driver', 'admin'], 'from' => ['assigned', 'no_answer', 'postponed', 'problem'], 'label' => 'En route'],
        'at_customer' => ['to' => 'at_customer', 'by' => ['driver', 'admin'], 'from' => ['assigned', 'en_route', 'no_answer', 'postponed'], 'label' => 'Chez le client'],
        'picked_up' => ['to' => 'picked_up', 'by' => ['driver', 'admin'], 'from' => ['assigned', 'en_route', 'at_customer', 'no_answer', 'postponed', 'problem'], 'types' => ['retour'], 'label' => 'Produit récupéré'],
        'exchanged' => ['to' => 'exchanged', 'by' => ['driver', 'admin'], 'from' => ['assigned', 'en_route', 'at_customer', 'no_answer', 'postponed', 'problem'], 'types' => ['echange'], 'label' => 'Échange effectué'],
        'no_answer' => ['to' => 'no_answer', 'by' => ['driver', 'admin'], 'from' => ['assigned', 'en_route', 'at_customer', 'postponed'], 'label' => 'Pas de réponse'],
        'postpone' => ['to' => 'postponed', 'by' => ['driver', 'admin'], 'from' => ['assigned', 'en_route', 'at_customer', 'no_answer', 'problem'], 'label' => 'Reporter'],
        'problem' => ['to' => 'problem', 'by' => ['driver', 'admin'], 'from' => ['assigned', 'en_route', 'at_customer', 'no_answer', 'postponed'], 'label' => 'Problème'],
        'returning' => ['to' => 'returning', 'by' => ['driver', 'admin'], 'from' => ['picked_up', 'exchanged'], 'label' => 'Retour au dépôt'],
        'receive' => ['to' => 'received', 'by' => ['admin'], 'from' => ['picked_up', 'exchanged', 'returning'], 'label' => 'Réceptionner au dépôt'],
        'close' => ['to' => 'closed', 'by' => ['admin'], 'from' => ['received'], 'label' => 'Clôturer'],
        'cancel' => ['to' => 'cancelled', 'by' => ['admin'], 'from' => ['created', 'to_assign', 'assigned', 'en_route', 'at_customer', 'no_answer', 'postponed', 'problem'], 'label' => 'Annuler'],
    ],
];
