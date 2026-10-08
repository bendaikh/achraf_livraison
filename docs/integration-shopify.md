# Intégration Shopify (synchronisation dans les deux sens)

Lav’Fast Flow parle à l’Admin API Shopify **2026-10** (version stable au 8 octobre 2026). Chaque boutique est une ligne `shopify_shops` rattachée à une société. Rien n’est codé pour une société, un domaine, un produit ou un transporteur en particulier.

Les tests n’appellent jamais Shopify ni un transporteur : `Http::preventStrayRequests()` est actif, et les réponses viennent de `Http::fake()` plus les fichiers `tests/Fixtures/shopify/`.

## Ce qui circule

- **Shopify → Flow.** Webhooks (`orders/*`, `products/*`, `inventory_levels/update`, `fulfillments/*`, `refunds/create`, `customers/*`) et une réconciliation GraphQL toutes les 15 minutes, plus un passage de 7 jours à 03:40. Les commandes GraphQL sont ramenées au même format que les webhooks, puis enregistrées par `OrderSyncService`.
- **Flow → Shopify.** Création (`orderCreate`, voir `docs/order-create.md`), modification de produit (`productUpdate`, `productVariantsBulkUpdate`, `inventorySetQuantities`), modification de commande (`orderEditBegin` … `orderEditCommit`, `orderUpdate`), annulation (`orderCancel`) et fulfillment (`fulfillmentCreate`, `fulfillmentTrackingInfoUpdate`). Le local n’est mis à jour qu’après une réponse acceptée. `draftOrderComplete` n’est pas utilisé : son `paymentPending` est déprécié et ne décrit pas un COD. `orderCreate` n’est pas idempotent ; la clé est `orders.creation_key`. L’annulation envoie `refundMethod` (plus le booléen `refund`).
- **Anti-boucle.** Un envoi mémorise 10 minutes les champs poussés. Le premier webhook qui revient avec les mêmes valeurs est journalisé `echo` puis la mémoire est effacée : un second changement identique n’est plus un écho. Une édition de lignes mémorise aussi les lignes (id, variante, quantité, prix), le total et le statut financier, pour qu’un paiement ou une annulation dans les 10 minutes ne soit pas pris pour l’écho.
- **File `shopify`.** Les jobs ont 6 essais (10 s, 1 min, 5 min, 15 min, 1 h) et respectent `Retry-After`. Une erreur 422 / `userErrors` échoue tout de suite. Le bouton Réessayer remet le même envoi en file.

`shop/redact` n’a pas été modifié : la boutique et ses commandes sont supprimées comme avant.

## Droits (scopes)

Demandés à la connexion, stockés en entier dans `granted_scopes` / `requested_scopes` (les colonnes `scopes` historiques restent en 255 caractères) :

`read_orders`, `write_orders`, `write_order_edits`, `read_customers`, `write_customers`, `read_products`, `write_products`, `read_inventory`, `write_inventory`, `read_locations`, `read_merchant_managed_fulfillment_orders`, `write_merchant_managed_fulfillment_orders`, `read_fulfillments`, `write_fulfillments`.

`write_*` couvre le `read_*` correspondant. `fulfillments_write` exige `write_merchant_managed_fulfillment_orders`. `inventory_write` exige en plus le drapeau société `shopify_push_stock` et un `inventory_location_id`.

Si un droit manque, l’écran affiche « Nouvelles autorisations Shopify nécessaires — Reconnecter Shopify ». Une modification de commande sans `write_order_edits` affiche « Modification Shopify non autorisée — reconnecter Shopify ».

Permissions applicatives ajoutées : `products.edit_shopify`, `products.edit_stock_shopify` (admin), `orders.edit_shopify_customer` (admin). L’envoi du suivi réutilise `orders.ship`. L’édition des lignes réutilise `orders.edit_items`, `orders.edit_prices` et `orders.discount`.

## Montant à encaisser

Shopify : `total_outstanding` quand il est connu, sinon `max(0, total − déjà payé)`. Une commande `paid` sans outstanding est traitée comme soldée. Manuel : payée → 0 ; partielle → reste ; sinon le total (paiement à la livraison).

Une commande Shopify encore marquée `items_edited_at` (ancien total local, avant l’édition via l’API) encaisse `max(0, total_price − déjà payé)` et non `total_outstanding`. Le total confirmé par l’équipe prime. Les nouvelles modifications de lignes et de remises ne posent plus `items_edited_at` : le total local ne change qu’après `orderEditCommit`.

Tant que `items_edited_at` est posé, Flow refuse d’éditer les lignes ou une remise (aucun appel Shopify) : « Cette commande contient des modifications locales jamais envoyées à Shopify (conflit). Choisissez d’abord « Reprendre la version Shopify ». » Le bouton recharge la commande en GraphQL, remplace les lignes et le total locaux par la version Shopify, recalcule le montant à encaisser, et note « Version Shopify reprise — modifications locales remplacées ». Rien n’est supprimé.

Les envois Speedaf, Ozon et Sift, la mission livreur et la clôture utilisent ce montant. S’il change après qu’un colis existe, Flow pose `amount_due_stale` et la note « Montant à encaisser modifié après l’envoi — vérifier le colis ». Aucun appel transporteur n’est fait pour corriger le colis.

## Automatisations

Déclencheurs (catégorie shopify) : `shopify.order_updated`, `shopify.order_edited`, `shopify.fulfillment_created`, `shopify.tracking_received`, `shopify.product_updated`, `shopify.sync_failed`.

Actions : `shopify.sync_order` (mêmes réglages qu’avant : pousser le tracking, créer le fulfillment, `{{vars.trackingNumber}}`), `shopify.create_fulfillment`, `shopify.update_tracking`, `shopify.add_order_note`.

Rien n’expédie vers Shopify parce qu’un transporteur a créé un colis. Il faut une automatisation, ou le bouton « Envoyer le suivi à Shopify ». La simulation n’appelle pas Shopify.

## Limite sur les prix

Baisser le prix d’une ligne vendue crée une remise de ligne. L’API d’édition ne permet pas d’augmenter le prix d’une ligne existante : Flow refuse et demande de remplacer le produit. Exemple FAST13050 : « Tapis Peugeot 208 » à 300 DH remplacé par « Tapis 7D Peugeot 208 » à 450 DH via « Remplacer produit » (`orderEditAddVariant` + quantité 0 + `orderEditCommit`, `notifyCustomer` false, note interne « Modifié depuis Lav’Fast Flow par {utilisateur} »).

Une remise du centre de confirmation sur une commande Shopify passe par `orderEditAddLineItemDiscount` (pourcentage : `percentValue` sur chaque ligne ; montant : parts proportionnelles au sous-total, la dernière ligne prenant le reste pour que la somme soit exacte), puis `orderEditCommit`. Chaque remise porte une description unique `Remise Lav’Fast Flow · {uuid}` (la raison de l’agent est placée devant). `order_discounts.shopify_discount_ids` garde `{ "tag", "ids" }` (une ancienne liste d’identifiants reste lisible). Le retrait ouvre une nouvelle session et retire les applications dont la description contient ce tag — les identifiants calculés changent d’une session à l’autre. Le total et le montant à encaisser ne bougent qu’après le commit. Sans `write_order_edits`, Flow refuse : « Modification Shopify non autorisée — reconnecter Shopify ».

`orders/edited` n’envoie pas la commande : le corps est `{ "order_edit": { "order_id", "line_items": { "additions", "removals" } } }`. Flow lit `order_edit.order_id`, recharge la commande en GraphQL et l’enregistre avec le déclencheur `shopify.order_edited`. La clé d’anti-chevauchement des jobs est cet `order_id` (ou `order_id` pour un fulfillment / un remboursement).

Les photos de catalogue restent celles de Shopify : Flow ne téléverse pas d’image. Flow ne crée pas non plus de produit pour une société déjà reliée à Shopify.

Le prix vendu d’une ligne (`price`) ne suit pas un changement ultérieur du prix catalogue (`catalog_price`).

## Mise en production

1. `php artisan migrate` (tables et colonnes nouvelles seulement).
2. Le worker planifié `queue-worker-automations` doit écouter `shopify,whatsapp-campaigns,automations,default` (`routes/console.php`, `Schedule::call`, pas `Schedule::command` : `proc_open` est désactivé sur l’hébergement).
3. Mettre à jour les scopes de l’app Shopify, puis le marchand doit **reconnecter**. Ensuite « Réenregistrer les webhooks ».
4. `SHOPIFY_API_VERSION=2026-10`.
5. Avant un vrai rattrapage des paiements : `php artisan shopify:refresh-payments --dry-run`. La réconciliation se lance aussi avec `php artisan shopify:reconcile --dry-run`.
