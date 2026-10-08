# Modes de livraison (barre groupée et fiche commande)

Une seule liste sert les trois entrées : la barre d’actions de **Commandes**, le bouton **Expédier** de chaque ligne, et le bloc **Expédition / Livraison** de la fiche. La livraison locale est une entrée de cette liste (`type: local`), pas un écran à part. Les livreurs locaux sont les livreurs : il n’y a pas une deuxième section « Livreur ».

## Ajouter un transporteur

1. Écrire une classe qui implémente `App\Services\Carriers\CarrierInterface` (les méthodes existantes : `key`, `label`, `color`, `unavailableReason`, `ship`, `shipmentFor`, `labels`, `scopeShipped`). Brancher la classe déjà écrite (`Speedaf`, `Ozon`, `Sift`) : ne pas recopier l’appel API.
2. L’enregistrer dans `config/carriers.php` (`carriers.drivers`). Aucune modification de `Orders.jsx` ni de `OrderDetail.jsx`.
3. Optionnel — `App\Services\Carriers\CarrierPresentation` :
   - `logoUrl()` : fichier dans `public/images/carriers/{key}.svg` (sinon pastille colorée avec les initiales de `color()`) ;
   - `actions()` : boutons de la fiche (`Suivre`, `Étiquette`, `BL`, `Actualiser statut`, `Annuler le colis`, et le reste sous « ⋯ »). Chaque action est `{ key, label, method, url }` ; l’URL peut contenir `{id}` ;
   - `documents()` : actions groupées (créer un BL, étiquettes, formats). Elles apparaissent dans « Envoyer avec › » (⋯ sur la ligne) et dans « Imprimer », pilotées par ces descripteurs, pas par la clé du transporteur ;
   - `history()` et `recentCount()` : colis annulés et usage des 30 derniers jours (`recent_rank`).
4. Optionnel — aperçu avant envoi : implémenter aussi `AdvancedCarrier` (`preview` sans appel HTTP). La barre ouvre alors le dialogue de validation existant. Sinon, le dialogue générique appelle le pré-contrôle.
5. Optionnel — rendu riche dans la fiche : une entrée dans `resources/js/components/delivery/carrierDetails.js`. Sans entrée, le bloc générique affiche logo, suivi, statut et les actions renvoyées par le serveur.

Connecter le transporteur dans **Intégrations** (pour que `unavailableReason()` retourne `null`) le fait apparaître comme cible d’envoi. Tant qu’il n’est pas connecté, il reste dans la section repliée « Non connectés », non cliquable.

`GET /api/carriers` reste disponible pour les filtres et les écrans déjà branchés dessus.

## `GET /api/delivery-modes`

Réponse :

- `modes[]` : `{ key, type: local|carrier, label, available, reason, logo, color, recent_rank, capabilities, actions, documents }` ;
- `can_ship`, `can_assign_driver`, `can_assign_agent` : masquent les sections que l’utilisateur ne peut pas utiliser ;
- `can_cancel` : `false` tant que l’annulation / suppression (tâche D) n’existe pas — le bouton « Annuler / Supprimer » reste caché ;
- `max_bulk` : 50, la même limite que `POST /api/carriers/{key}/ship` ;
- `user_id`, `company_id` : clé du `localStorage` des transporteurs récemment utilisés (`lavfast:delivery-recent:{user}:{company}`, 3 maximum). Sans historique navigateur, l’ordre retombe sur `recent_rank` (usage de la société sur 30 jours).

La fiche lit `order.delivery_mode` (`OrderResource`) : `{ type, key, label, logo, tracking, status_label, tracking_url, actions, history }` ou, en local, le livreur et la mission. Seul le mode réellement utilisé est affiché.

## Pré-contrôle et envoi partiel

`POST /api/delivery-modes/{key}/check` avec `{ order_ids }` (maximum 50) ne contacte pas le transporteur.

- Transporteur `AdvancedCarrier` : son `preview()` local, plus le refus « déjà envoyé à un autre transporteur ».
- Les autres : déjà expédiée, commande non confirmée, statut terminé / annulé / retour, nom, téléphone, ville ou adresse manquants.

Réponse : `selected`, `ready`, `problems`, `rows[]` avec `reference`, `amount_due` (jamais `total_price`), `can_send`, `reason`.

Le dialogue affiche « {n} sélectionnées · {ok} prêtes · {ko} avec problème » et le bouton « Envoyer les {ok} commandes prêtes ». Seules les commandes prêtes partent, par paquets de 50 (`max_bulk`), avec une progression. Un échec transporteur sur une commande ne bloque pas les autres : `ship()` renvoie un résultat par commande. Le tiroir résume « {sent} envoyée(s) · {failed} échec(s) » ; la sélection restante ne garde que les échecs.

Le montant transmis au transporteur est `Order::amountDue()` (« À encaisser »). Une commande payée par carte part avec un COD à 0.

Changer de mode sur une commande déjà expédiée exige d’abord l’annulation chez le transporteur lorsqu’une action `cancel` existe. Aucun second colis actif n’est créé.
