<?php

/*
| T9 — Automatic client segments. Each segment is a set of thresholds evaluated on the
| per-client aggregates (computed from orders). Editable here; generic for every company.
| Available metrics: orders, delivered, returned, cancelled, confirmed, return_rate,
| cancel_rate, delivery_rate (0-100), days_since_first, days_since_last, total.
| Operators: min / max.
*/
return [
    'segments' => [
        'good' => ['label' => 'Bons clients', 'color' => '#059669', 'description' => 'Au moins 2 livraisons et moins de 20 % de retours', 'rules' => ['delivered' => ['min' => 2], 'return_rate' => ['max' => 19.99]]],
        'multi_delivered' => ['label' => 'Plusieurs livraisons', 'color' => '#2563eb', 'description' => 'Au moins 2 commandes livrées', 'rules' => ['delivered' => ['min' => 2]]],
        'high_return' => ['label' => 'Retours élevés', 'color' => '#dc2626', 'description' => 'Au moins 2 commandes et 30 % de retours ou plus', 'rules' => ['orders' => ['min' => 2], 'return_rate' => ['min' => 30]]],
        'frequent_cancel' => ['label' => 'Annulations fréquentes', 'color' => '#d97706', 'description' => 'Au moins 2 commandes annulées', 'rules' => ['cancelled' => ['min' => 2]]],
        'new' => ['label' => 'Nouveaux clients', 'color' => '#7c3aed', 'description' => 'Première commande il y a moins de 30 jours', 'rules' => ['days_since_first' => ['max' => 30]]],
    ],
    'block_reasons' => ['Refus répétés à la livraison', 'Faux numéro / commande fictive', 'Comportement abusif', 'Retours excessifs', 'Autre'],
];
