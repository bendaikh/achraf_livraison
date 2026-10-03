<?php

/*
| T6 — Agent remuneration. Modes and triggers are a registry (labels editable here, new
| modes = new entry + a calculation kind). kind: fixed (per order), percent (of the order
| total), monthly (fixed per month), none.
*/
return [
    'modes' => [
        'none' => ['label' => 'Aucune commission', 'kind' => 'none'],
        'per_confirmed' => ['label' => 'Fixe par commande confirmée', 'kind' => 'fixed', 'default_trigger' => 'confirmation'],
        'per_delivered' => ['label' => 'Fixe par commande livrée', 'kind' => 'fixed', 'default_trigger' => 'delivered'],
        'percent' => ['label' => '% du montant de la commande', 'kind' => 'percent', 'default_trigger' => 'delivered'],
        'monthly' => ['label' => 'Fixe mensuel', 'kind' => 'monthly'],
    ],
    'triggers' => [
        'confirmation' => 'À la confirmation',
        'shipped' => 'Après expédition',
        'delivered' => 'Uniquement après livraison',
        'closed' => 'Après livraison + clôture validée',
        'manual' => 'Autre (validation manuelle)',
    ],
    'states' => [
        'pending' => 'En attente',
        'validated' => 'Validée',
        'paid' => 'Payée',
        'cancelled' => 'Annulée',
    ],
];
