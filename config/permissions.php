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
        'orders.edit_shopify_customer' => ['label' => 'Modifier le client Shopify d’une commande', 'roles' => ['admin']],
        'orders.edit_prices' => ['label' => 'Modifier le prix d’un produit dans une commande', 'roles' => ['admin']],
        'orders.discount' => ['label' => 'Ajouter / retirer une remise sur une commande', 'roles' => ['admin']],
        'orders.assign_agent' => ['label' => 'Assigner des commandes aux agents de confirmation', 'roles' => ['admin']],
        'dashboard.view' => ['label' => 'Voir le tableau de bord et le Centre', 'roles' => ['admin']],
        'settings.manage' => ['label' => 'Gérer les paramètres et les intégrations', 'roles' => ['admin']],
        'drivers.manage' => ['label' => 'Gérer les livreurs et les missions', 'roles' => ['admin']],
        'closings.manage' => ['label' => 'Clôture financière (caisse livreurs)', 'roles' => ['admin']],
        'users.manage' => ['label' => 'Gérer les utilisateurs et les services', 'roles' => ['admin']],
        'team.view_all' => ['label' => 'Voir la performance de toute l’équipe', 'roles' => ['admin']],
        'commissions.manage' => ['label' => 'Valider et payer les commissions des agents', 'roles' => ['admin']],
        'whatsapp.access' => ['label' => 'Accéder au module WhatsApp', 'roles' => ['admin', 'user']],
        'campaigns.view' => ['label' => 'Voir les campagnes WhatsApp', 'roles' => ['admin', 'user']],
        'campaigns.manage' => ['label' => 'Créer / modifier / archiver les campagnes WhatsApp', 'roles' => ['admin']],
        'campaigns.send' => ['label' => 'Envoyer / programmer / suspendre les campagnes WhatsApp', 'roles' => ['admin']],
        'products.view' => ['label' => 'Voir le catalogue produits', 'roles' => ['admin', 'user']],
        'clients.view' => ['label' => 'Voir les clients (fiche, historique, notes)', 'roles' => ['admin', 'user']],
        'clients.block' => ['label' => 'Bloquer / débloquer un client', 'roles' => ['admin']],
        'clients.groups' => ['label' => 'Gérer les groupes de clients', 'roles' => ['admin']],
        'clients.tags' => ['label' => 'Gérer les tags clients', 'roles' => ['admin', 'user']],
        'sav.manage' => ['label' => 'Créer et suivre les retours / échanges', 'roles' => ['admin', 'user']],
        'sav.depot' => ['label' => 'Réceptionner au dépôt et clôturer les retours / échanges', 'roles' => ['admin']],
        'products.sync' => ['label' => 'Synchroniser le catalogue Shopify', 'roles' => ['admin']],
        'products.edit_shopify' => ['label' => 'Modifier un produit Shopify (titre, prix, SKU)', 'roles' => ['admin']],
        'products.edit_stock_shopify' => ['label' => 'Modifier le stock Shopify', 'roles' => ['admin']],
        'automations.view' => ['label' => 'Voir les automatisations et les logs', 'roles' => ['admin']],
        'automations.manage' => ['label' => 'Créer / modifier / activer / archiver des automatisations', 'roles' => ['admin']],
        'automations.test' => ['label' => 'Tester une automatisation (simulation)', 'roles' => ['admin']],
    ],
];
