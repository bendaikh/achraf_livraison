# Statuts de confirmation

Les statuts de confirmation sont configurables par société. Ils vivent dans `confirmation_statuses` et s’appliquent à la même ligne `orders` (`confirmation_status`). Ils ne remplacent ni le statut de livraison (`delivery_status`) ni les statuts transporteur.

## Catégories

| Code | Libellé |
|---|---|
| `en_attente` | En attente |
| `confirmee` | Confirmée |
| `pas_de_reponse` | Pas de réponse |
| `a_recontacter` | À recontacter / programmée |
| `probleme_stock` | Problème stock |
| `attente_client` | Attente client |
| `annulee_echec` | Annulée / échec |
| `personnalise` | Personnalisé |

Les cinq codes déjà en production restent valides : `to_confirm`, `postponed`, `no_answer`, `confirmed`, `cancelled`. Leur catégorie est déduite à la migration (`to_confirm` → `en_attente`, `postponed` → `a_recontacter`, `no_answer` → `pas_de_reponse`, `confirmed` → `confirmee`, `cancelled` → `annulee_echec`). Un statut personnalisé déjà présent garde son `type` et son `queue_behavior` : `success` devient confirmé, `cancelled` devient un échec, `future_only` exige une date de rappel, `due_queue` reste dans la file. Un nouvel enregistrement dans Paramètres ne change pas ce comportement. Relancer le provisionnement ne réécrit pas un statut système déjà modifié.

## Drapeaux

`stays_in_queue`, `counts_as_confirmed`, `counts_as_failure`, `requires_recall_date`, `requires_time`, `requires_reason`, `requires_comment`, `requires_product`, `is_final` (synchronisé avec `is_terminal`). `show_in_filters` place le statut dans les onglets principaux ; les autres sont dans « Autres statuts ▾ ». `reason_options` est un JSON de motifs. `is_system` fige le code et la catégorie.

À l’enregistrement, `type` et `queue_behavior` sont dérivés des drapeaux. Un statut « à recontacter » qui exige une date de rappel sort de la file jusqu’à l’échéance (`future_only`). Un rappel échu, quel que soit le code, revient dans la file « À confirmer ».

Les compteurs, le tableau de bord, les clients, les commissions et les performances d’équipe utilisent `counts_as_confirmed` et `counts_as_failure`, pas seulement les codes historiques.

## Une liste par société

`company_id` est obligatoire après la migration. L’index unique global sur `code` est remplacé par l’unique `(company_id, code)`. Les lignes existantes reçoivent la société par défaut, puis chaque code est copié vers les autres sociétés s’il n’y existe pas encore. Aucune ligne, aucun historique et aucune commande n’est supprimé. `down()` retire les colonnes ajoutées ; il ne rétablit pas l’unicité globale (des codes identiques dans deux sociétés rendraient ce retour impossible).

Une société créée ensuite reçoit les cinq statuts système (`Company::created` → `ConfirmationStatusProvisioner`). Le seeder provisionne la société par défaut, car la migration des sociétés insère la ligne sans événement Eloquent. En test, la table est vide au moment de la migration : la copie ne trouve rien, puis le seeder et `Company::create` provisionnent.

Le cache est `confirmation_statuses.{companyId}`. Les lectures (`findByCode`, file, meta) prennent la société de la commande, sinon celle de l’utilisateur.

## Un seul chemin de changement

`POST /api/confirmation/orders/{order}/status` passe par `ConfirmationStatusChanger`. Les boutons Confirmer, Pas de réponse, Reporter et Annuler (confirmation) résolvent le statut configuré de la société (code système, sinon catégorie / drapeau) et appellent le même service. Un statut inactif ou d’une autre société répond 422 : « Ce statut est inactif ou n’appartient pas à cette société. »

Un statut déjà utilisé ne se supprime pas (422 : « Ce statut est déjà utilisé : vous pouvez seulement le désactiver. »). Un statut système non plus. Un statut personnalisé jamais utilisé peut être supprimé. L’écran est **Paramètres → Statuts de confirmation** (`settings.manage`), bouton « + Nouveau statut ».

L’historique immuable reste `order_status_histories` (`kind = confirmation`) plus `confirmation_history`. La note de confirmation est `orders.confirmation_note` (colonne nullable).

## Automations

Le déclencheur `order.confirmation_changed` reçoit `from_status`, `to_status`, `to_category`, `reason`, `recall_at` (et `from` / `to`). Les déclencheurs `order.confirmed`, `order.cancelled`, `order.no_answer` et `order.postponed` partent des drapeaux et de la catégorie. L’action `order.set_confirmation_status` (« Changer le statut de confirmation ») passe par le même service. En simulation, le statut de la commande n’est pas écrit. Les conditions `confirmation_status` et `confirmation_category` proposent la liste de la société.

La règle « stock disponible → À rappeler » n’est pas créée : le déclencheur `shopify.product_updated` existe déjà.

## Déploiement

1. Exporter `confirmation_statuses` (sauvegarde). Ne rien supprimer.
2. `php artisan migrate`
3. Vérifier, pour chaque société, une copie des codes système et des compteurs cohérents.
4. `php artisan optimize:clear`

## Ce qui n’est pas entièrement dynamique

Les quatre libellés des boutons rapides restent « Confirmer », « Pas de réponse », « Reporter » et « Annuler (confirmation) ». Ils pointent vers le statut configuré, y compris si son nom a été renommé. Les quatre cartes KPI principales gardent leurs titres ; le détail par statut est dynamique. Les trois seaux de rappel (En retard, Aujourd’hui, À venir) sont fixes, en fuseau société (`Africa/Casablanca` par défaut). La palette d’icônes du formulaire est une liste courte. Confirmer une commande sans statut de livraison pose toujours le statut de livraison initial : ce n’est pas un mélange des deux statuts quand un statut de livraison existe déjà.
