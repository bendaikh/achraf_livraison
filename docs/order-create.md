# Création de commande, brouillon et annulation

Le bouton **Nouvelle commande** ouvre un formulaire (client, catalogue synchronisé, totaux, paiement, notes, commercial). **Créer la commande** enregistre la ligne `orders` puis, si la boutique de la société le permet, la commande Shopify. **Enregistrer comme brouillon** ne quitte pas Lav’Fast Flow et n’appelle pas Shopify.

Il n’y a pas de table parallèle : un brouillon, une commande créée dans Flow et une commande reçue de Shopify sont la même ligne `orders`.

## Pourquoi `orderCreate`

L’API Admin **2026-10** crée la commande avec `orderCreate` (`OrderCreateOrderInput`). `draftOrderCreate` puis `draftOrderComplete` ne convient pas : `paymentPending` sur `draftOrderComplete` est déprécié et ne décrit pas un paiement à la livraison marocain (COD).

`orderCreate` n’est pas `@idempotent`. L’unicité est locale (`orders.creation_key`, UUID).

Choix imposés par l’API :

- Une seule remise, via `discountCode` : `itemPercentageDiscountCode` (pourcentage) ou `itemFixedDiscountCode` (montant), code `LAVFAST`.
- Le COD est une transaction `SALE` / `PENDING` (le statut par défaut serait `SUCCESS`) sur la passerelle `Cash on Delivery`, `financialStatus: PENDING`.
- Payé : `PAID` et une transaction `SUCCESS`. Partiel : `PARTIALLY_PAID`, `SUCCESS` sur la part payée et `PENDING` COD sur le reste.
- Les frais sont une ligne sans variante (`requiresShipping: false`). La livraison est une `shippingLine` « Livraison » seulement si le montant est supérieur à 0.
- Le prix catalogue n’est pas renvoyé. Un prix modifié (permission `orders.edit_prices`) part dans `priceSet`.
- `options.sendReceipt` et `sendFulfillmentReceipt` sont `false`. `inventoryBehaviour` vaut `DECREMENT_OBEYING_POLICY`, sauf le réglage société `features.shopify_inventory_behaviour` (`BYPASS`, `DECREMENT_IGNORING_POLICY` ou `DECREMENT_OBEYING_POLICY`).
- `sourceName` est `lavfast-flow`. La source affichée dans Flow est `Lav’Fast Flow` (apostrophe typographique).

L’annulation utilise `orderCancel` et `refundMethod.originalPaymentMethodsRefund` (le booléen `refund` est déprécié). Par défaut : pas de remboursement, `restock: true`, `notifyCustomer: false`. Le job renvoyé n’est pas toujours terminé : `shopify_sync_status` reste `pending` jusqu’au webhook `orders/cancelled`.

`write_orders` couvre déjà `orderCreate` et `orderCancel`. Aucun scope nouveau, donc pas de reconnexion obligatoire.

## Déroulement

1. Le navigateur envoie `POST /api/orders/flow` avec `creation_key` (UUID conservé pour le brouillon et pour Réessayer).
2. Le serveur vérifie le catalogue de la société, le stock (`deny` bloque avec « Stock insuffisant » ; un stock suivi insuffisant mais autorisé ajoute l’avertissement « Stock insuffisant »), les prix, la remise et le commercial (utilisateur actif, non livreur, qui peut créer).
3. Une transaction pose un verrou sur `creation_key` :
   - déjà créée dans Shopify → `200`, aucun second `orderCreate` ;
   - `flow_state = creating` → `409` « Création déjà en cours… », aucun appel ;
   - sinon la ligne est enregistrée d’abord (`pending` / `creating`, ou `draft`).
4. Boutique absente ou sans `orders_create` : la commande reste dans Flow, avec l’avis « Boutique Shopify non connectée : commande créée uniquement dans Lav’Fast Flow ». Aucun HTTP.
5. Sinon `orderCreate`. Une erreur non rejouable laisse le brouillon (`shopify_sync_status = failed`, message français). **Réessayer** (`POST /api/orders/{id}/flow-retry`) réutilise la même clé. Un `429` est rejoué jusqu’à 3 fois, sans pause. Un téléphone refusé est renvoyé une fois sans fiche client ; le téléphone reste sur l’adresse, et la réponse porte un avertissement, pas un échec.
6. Après succès, `OrderSyncService::upsertFromShopifyPayload(..., 'flow_user')` rattache les identifiants Shopify. La source, le créateur, le commercial, la note interne, la clé et le statut de confirmation Flow sont conservés. Le déclencheur `order.created` part seulement à ce moment (un brouillon ou un `creating` ne le déclenche pas).

Un délai ambigu (réseau, statut HTTP inconnu, 5xx, « injoignable ») cherche d’abord `orders(query: "tag:lavfast-flow")` et reconnaît la clé dans l’attribut `lavfast_creation_key`, `sourceIdentifier` ou un tag UUID, avant tout second `orderCreate`.

## Écho

`orders/create` peut arriver avant la réponse de la mutation. La ligne locale existe déjà. `upsertFromShopifyPayload` cherche, pour un `shopify_order_id` inconnu, une commande de la même société dont `creation_key` correspond. Il y attache les identifiants Shopify, passe `flow_state` à `created`, et journalise `echo`. Un conflit `pending` / `failed` ne s’applique pas à cet appariement, ni quand le payload porte `cancelled_at`.

Après un envoi `flow_user`, `SyncEcho::remember` mémorise les champs renvoyés. Le webhook qui suit est aussi un écho, et `shopify.order_updated` n’est pas déclenché.

## Permissions

| Capacité | Rôles par défaut |
|---|---|
| `orders.create` | admin, user |
| `orders.edit` | admin, user |
| `orders.edit_prices` | admin |
| `orders.discount` | admin |
| `orders.cancel` | admin |
| `orders.delete_draft` | admin, user |
| `orders.view_all` | admin, user |

Sans `orders.view_all`, `Order::scopeVisibleTo` limite aux commandes dont l’utilisateur est le créateur, le commercial ou l’agent assigné. La liste Commandes, le kanban, la recherche, la fiche et la file Confirmation utilisent ce filtre. Un super-admin voit tout. Le remboursement Shopify reste réservé à un admin ou un super-admin, même si `orders.cancel` est donné à un autre rôle.

## Supprimer et annuler

**Supprimer** (`DELETE /api/orders/{id}/draft`, groupe `POST /api/orders/delete-drafts`) exige `orders.delete_draft` et un brouillon sans conséquence : pas d’identifiant Shopify, pas de colis, pas de mission ni de livreur, pas de paiement, pas de clôture, pas de livraison, pas de SAV. Confirmation : « Supprimer définitivement ce brouillon ? ». La ligne reçoit `deleted_at` (scope global `not_deleted`) et une ligne `order_deletions` (utilisateur, motif, instantané). Rien n’est effacé de la base.

**Annuler** (`POST /api/orders/{id}/cancel`, groupe `POST /api/orders/cancel`) concerne une commande créée ou synchronisée. Motifs : « Client a annulé » (`CUSTOMER`), « Doublon » (`OTHER`), « Erreur de saisie » (`STAFF`), « Rupture de stock » (`INVENTORY`), « Autre » (`OTHER`).

Refus :

- colis actif : « Annulez d’abord le colis chez {transporteur} » ;
- livrée ou clôturée : « Commande livrée / clôturée : utilisez un retour (SAV) » ;
- mission autre que `a_faire` / `annulee` : « Mission déjà commencée ».

Une mission `a_faire` passe à `annulee` seulement après l’acceptation Shopify. Un refus Shopify ne change rien en local ; le journal `orderCancel` se termine par « Réessayer ».

L’annulation pose `status = cancelled`, `cancelled_at`, `cancelled_by`, `cancel_reason`, `cancel_comment`. Elle ne change pas `confirmation_status`. Le montant à encaisser passe à 0. L’historique (kind `commande` et `confirmation_history`) garde l’utilisateur, l’heure, le motif, le commentaire, et la situation avant / après (statut, confirmation, livraison, suivi, montant à encaisser).

Les brouillons et les commandes `cancelled` sortent des files Confirmation et Expédition (`inWorkflowQueues`). La liste Commandes les affiche (badge Brouillon, filtre Brouillons, badge Annulée).

Le groupe renvoie `200` et un résultat par commande (`ok`, `message`).

## Mise en production

1. `php artisan migrate` (colonnes nullables et table `order_deletions` seulement).
2. Les scopes de l’app Shopify ne changent pas : `write_orders` suffit. Reconnecter seulement si `granted_scopes` de la boutique ne contient pas `write_orders`.
3. Essayer d’abord sur une boutique de développement. Shopify limite la création (quelques commandes par minute) : le message affiché est « La boutique Shopify limite la création de commandes (quelques commandes par minute). Réessayez dans un instant. »
4. `orders.view_all` est accordé à `admin` et `user`. Une société qui veut restreindre la visibilité le retire dans Paramètres → Équipe.
