<?php

/*
| Abilities checked with Gate / $user->can('…') and exposed to the SPA (user.permissions).
| "roles" = default roles granted. A company can override the role list of an ability through
| the setting "role_permissions" ({"ability": ["admin", …]}) — UI in Paramètres → Équipe (T6).
| superadmin always has every ability.
*/
return [
    'abilities' => [
        'orders.assign_driver' => ['label' => 'Affecter / réaffecter des commandes à un livreur local', 'roles' => ['admin']],
        'orders.ship' => ['label' => 'Envoyer des commandes aux sociétés de livraison', 'roles' => ['admin']],
        'orders.edit_items' => ['label' => 'Ajouter / remplacer / supprimer des produits d’une commande', 'roles' => ['admin', 'user']],
        'orders.edit_prices' => ['label' => 'Modifier le prix d’un produit dans une commande', 'roles' => ['admin']],
        'orders.discount' => ['label' => 'Ajouter / retirer une remise sur une commande', 'roles' => ['admin']],
        'products.view' => ['label' => 'Voir le catalogue produits', 'roles' => ['admin', 'user']],
        'products.sync' => ['label' => 'Synchroniser le catalogue Shopify', 'roles' => ['admin']],
    ],
];
