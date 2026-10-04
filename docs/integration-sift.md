# Intégration Sift.ma (T8)

Transporteur « Sift » branché dans le registre des sociétés de livraison
(`config/carriers.php` → `App\Services\Carriers\SiftCarrier`, interfaces `CarrierInterface` +
`AdvancedCarrier`), sur le même modèle qu’Ozon Express (T14). Speedaf, Ozon Express et la livraison
locale continuent de fonctionner comme avant. Aucune collection Postman n’a été fournie : brahim a
validé qu’on avance sans le JSON.

## 1. Sources

* **Spécification T8** de brahim (`lavfast_tasks_2026-10-03.md`) : endpoints, champs métier, règles.
* **Appels réels** à `https://apis.sift.ma`, sans clé, le 04/10/2026.
* **Application marchande publique** `app.sift.ma`, bundle JavaScript : liste des codes de statut
  (`PARCEL_STATUS_OPTIONS`) et leurs libellés.
* **Recherches** sur sift.ma, apis.sift.ma, GitHub, Postman et le web : **aucune documentation API
  publique**. La page `/api-integration` de l’app est réservée aux comptes connectés. Les résultats
  « Sift API » concernent un autre produit, Sift Science (anti-fraude), sans rapport.

Légende : ✅ confirmé par un appel réel · 📄 donné par la spec T8 · 🔎 vu dans l’app publique · ❓ incertain
(code défensif, à vérifier avec la clé).

## 2. Connexion

| Élément | Valeur | Niveau |
|---|---|---|
| Base | `https://apis.sift.ma/v1` (`/` redirige en 307 vers `/v1`). L’index public renvoie `{"success":true,"data":{"name":"Sift.ma API Integration","version":"v1","resources":{"parcels":"/parcels","products":"/products","webhooks":"/webhooks"},"compatibilityPath":"/api/integration/v1"}}` | ✅ |
| Auth | `Authorization: Bearer <clé>` **ou** `X-API-Key: <clé>` (au choix dans la config) | ✅ (message 401) + 📄 |
| Clé absente ou invalide | HTTP 401 `{"success":false,"error":"unauthorized","message":"Invalid or missing API key. Provide a valid API key in the Authorization header (Bearer token) or X-API-Key header."}` | ✅ |
| Chemin inconnu | HTTP 404 `{"success":false,"error":"not_found","message":"Unknown API integration endpoint."}` | ✅ |
| Enveloppe des réponses | `{success, data, error, message}` | ✅ (erreurs) / ❓ (succès) |
| « Tester la connexion » | `GET /parcels?page=1&limit=1` : 200 = clé acceptée, 401/403 = clé refusée | ✅ route / ❓ corps |

**Clé API** : chiffrée en base (`sift_settings.api_key`, cast `encrypted`, `$hidden`), en écriture
seule. Le navigateur ne reçoit qu’un indice masqué (`••••••••abcd`). La clé est remplacée par `••••`
dans les messages, les réponses et le journal (`SiftClient::redact`), et ne sort jamais du serveur :
les étiquettes PDF passent par notre serveur.

## 3. Routes (vérifiées par OPTIONS : en-tête `allow`)

| Route | Méthodes | Usage dans Lav'Fast Flow | Niveau |
|---|---|---|---|
| `/parcels` | GET, POST | création, liste (contrôle et resync) | ✅ |
| `/parcels/{id}` | GET, PUT, DELETE | actualiser, modifier, annuler (PUT status), supprimer (soft) | ✅ |
| `/parcels/{id}/waybill` | GET | étiquette PDF | ✅ |
| `/parcels/tracking/{trackingNumber}` | GET | recherche par n° de suivi | ✅ |
| `/products` | GET | préparation uniquement | ✅ |
| `/webhooks`, `/webhooks/{id}` | GET, POST / GET, PATCH, DELETE | « Enregistrer le webhook chez Sift » | ✅ |
| `/parcels/{id}/status`, `/parcels/{id}/cancel` | 404 | n’existent pas : l’annulation passe donc par `PUT /parcels/{id}` `{"status":"cancelled"}` | ✅ |

## 4. Création du colis : `POST /parcels` (📄 champs, ❓ noms exacts)

Corps envoyé (`SiftShipmentService::build`) :

```json
{
  "customOrderNo": "1042",
  "customerName": "Fatima Zahra",
  "customerPhone": "0612345678",
  "address": "12 Rue Atlas, Agdal",
  "city": "Rabat",
  "items": [
    {"sku": "ROBE-M", "name": "Robe", "quantity": 2, "price": 100},
    {"name": "Ceinture", "quantity": 1, "price": 50}
  ],
  "quantity": 3,
  "price": 250,
  "codAmount": 250,
  "cod": true,
  "notes": "Appeler avant",
  "allowOpen": true
}
```

* **customOrderNo** = notre numéro de commande (référence sans `#`). Sift renvoie le colis existant si
  ce numéro existe déjà : c’est l’anti-doublon côté Sift. 📄
* **Articles** : réglage « Lignes produits ». En mode *manuel* (par défaut), toutes les lignes partent
  en lignes manuelles (nom, quantité, prix), sans `sku`. En mode *SKU*, les lignes qui ont un SKU
  Shopify l’envoient (liées au stock chez Sift) et les autres restent manuelles. Aucun SKU n’est
  inventé. Shopify reste le catalogue.
* **COD** = total de la commande si paiement à la livraison, sinon 0.
* **Téléphone** normalisé au format `06…` (même fonction que Speedaf).
* **Ville** : envoyée en texte libre. ❓ Sift peut attendre une liste de villes, à vérifier au premier
  envoi réel.
* **Notes** : note de la commande si « Envoyer la note » est activé.
* **Ouverture** (`allowOpen`) : valeur par défaut dans la config, modifiable dans la fenêtre de validation.

**Réponse lue** (défensivement) : `parcelId | parcel_id | id | _id`, `trackingNumber | tracking_number | trackingNo`,
`customOrderNo`, `status`, `subStatus`. Le résultat est enregistré dans `sift_shipments` (`parcel_id`,
`tracking_number`, `custom_order_no`).

**Colis existant** ❓ : le signal exact n’est pas connu. Est traité comme « colis existant renvoyé
par Sift » :
* un drapeau `existing`, `duplicate` ou `alreadyExists` à `true` ;
* un HTTP 200 avec `created: false` ;
* un HTTP 409 qui contient le colis.

Dans tous les cas on rattache ce colis, sans en créer un second (`reused_existing = 1`, mention
dans l’historique). Si le colis existant est déjà annulé, l’envoi est refusé avec un message clair.

**Anti-doublon local** :
* verrou de 60 s par commande ;
* refus si un colis Sift actif existe déjà (« Cette commande est déjà envoyée à Sift ») ;
* refus si la commande a un colis Speedaf, Ozon ou local (vérifié par le registre) ;
* en cas de timeout ou de réponse perdue, recherche `GET /parcels?customOrderNo=` avant d’abandonner.

**Renvoi après annulation** : `customOrderNo` reçoit un suffixe (`1042-R2`, `1042-R3`…), sinon Sift
renverrait le colis annulé.

## 5. Autres opérations

| Action | Appel | Règle |
|---|---|---|
| Actualiser depuis Sift | `GET /parcels/{id}` (ou `/parcels/tracking/{tn}` sans parcelId) | ✅ route |
| Modifier | `PUT /parcels/{id}` avec `customerName`, `customerPhone`, `address`, `city`, `codAmount`, `notes` | 📄 uniquement quand le statut brut est `pending` (vérifié avant l’appel) |
| **Annuler chez le transporteur** | `PUT /parcels/{id}` `{"status":"cancelled"}` | 📄 la commande redevient expédiable |
| **Supprimer / masquer localement** | `DELETE /parcels/{id}` (suppression logique chez Sift) + `hidden_at` local | 📄 refusé tant que le colis est actif : « annulez d’abord chez le transporteur ». Les deux actions sont séparées dans l’interface. |
| Étiquette | `GET /parcels/{id}/waybill?format=…` | 📄 formats `STANDARD_100x100`, `A4`, `THERMAL_150x100`, `SIFT`, `SIFT_1`, `SIFT_2`. ❓ nom du paramètre (`format`). PDF brut ou PDF en base64 dans du JSON acceptés. |
| Liste / contrôle | `GET /parcels?page&limit&status&city&search&customOrderNo` | 📄 filtres. ❓ noms des champs de pagination (`pagination` / `meta`, `page`, `total`, `totalPages`… tous lus) |
| Resynchroniser | `GET /parcels?customOrderNo=` puis rattachement | égalité stricte du customOrderNo exigée, au cas où Sift ignorerait le filtre |
| Produits | `GET /products?search=&limit=` | 📄 lecture seule |
| Webhook | `POST /webhooks` `{url, events, secret, active}` | ❓ corps exact. Le bouton est optionnel : l’URL et le secret peuvent être collés à la main dans Sift. |

## 6. Webhooks

* **URL** : `https://lavfast-flow.com/sift/webhook/{token}`. Le token fait 40 caractères aléatoires
  par société. La route est exclue du CSRF. La page affiche l’URL et le secret avec un bouton
  « Copier ».
* **Secret dédié** : `whsec_…`, chiffré, séparé de la clé API, affiché seulement sur demande,
  régénérable.
* **Événements** : `parcel.status_changed`, `parcel.delivered`, `return.status_changed`. 📄
* **Signature** ❓ : le schéma n’est pas publié. On accepte les variantes HMAC-SHA256 calculées avec le
  secret :
  * en-têtes de signature : `X-Sift-Signature`, `X-Signature`, `X-Webhook-Signature`,
    `X-Hub-Signature-256`, `Sift-Signature` ;
  * valeur : hex ou base64 du HMAC du corps brut, avec préfixe `sha256=` optionnel ;
  * format Stripe `t=…,v1=…` ;
  * ou en-tête d’horodatage (`X-Sift-Timestamp`, `X-Timestamp`) avec HMAC de `ts.corps` ou `ts\ncorps`.

  Avec horodatage, la tolérance est de 10 minutes. Une signature absente ou invalide est refusée en
  **401** et enregistrée comme événement « refusé » avec **les noms des en-têtes seulement** :
  le premier vrai appel de Sift montrera le schéma réel dans l’onglet Webhooks.
* **Idempotence** : sur l’id de l’événement (`eventId`, `event_id`, `id`, ou en-têtes
  `X-Sift-Event-Id` / `X-Webhook-Id`). À défaut, sha1 du corps. Un événement déjà traité répond 200
  sans rien refaire.
* **Traitement** :
  1. trouver le colis (parcelId, puis n° de suivi, puis customOrderNo) ;
  2. conserver le statut brut et le sous-statut ;
  3. ajouter le statut à la liste « reçus » ;
  4. appliquer le mapping configurable ;
  5. ajouter une entrée à l’historique de la commande (« Webhook Sift : … »).

  Un colis inconnu est noté « ignoré » avec une réponse 200. ❓ Forme du payload : `data.parcel`,
  `data` ou corps plat, les trois sont lus.
* **Secours** : la commande `sift:sync` interroge `GET /parcels/{id}` pour les colis en cours,
  toutes les 30 min si « Synchronisation automatique » est activée (Schedule `sift-sync`). Le bouton
  « Synchroniser maintenant » fait la même chose à la demande.

## 7. Statuts

Codes trouvés dans l’app marchande 🔎 (`SiftStatusMap::LABELS`), avec le mapping par défaut vers
Lav'Fast Flow, modifiable dans **Paramètres → Transporteurs → Sift.ma** :

| Codes Sift | Lav'Fast Flow |
|---|---|
| `picked_up`, `in_transit`, `yet_to_arrive`, `out_for_delivery` | En cours |
| `rescheduled` | Reportée |
| `injoignable` | Pas de réponse |
| `invalid_address`, `damaged`, `delivery_exception`, `out_of_coverage`, `lost`, `rejected` | Échec |
| `return_in_progress`, `return_to_client`, `returned`, `return_to_stock` | Retournée |
| `delivered` | Livrée (montant encaissé = COD) |
| `cancelled` | Annulée (le colis passe à l’état annulé) |
| `pending`, `awaiting_pickup`, `processing`, `address_change`, `mal_tri`, `duplicate_order`, `force_majeure`, `advance_payment_required`, `agency_pickup`, `deleted` | aucun changement |

Un statut inconnu est conservé brut, ajouté au tableau (« reçu ») sans changer la commande, puis
associé à la main. ❓ Les codes envoyés par l’API ou les webhooks peuvent différer de ceux de l’app.
Sift semble s’appuyer sur Speedaf en interne (le bundle contient `cleanSpeedafMessage`).

## 8. Interface

* **Intégrations → Sift.ma** (`/integrations/sift`), onglets :
  * *Configuration* : clé, URL préremplie, mode d’envoi de la clé, actif, Tester la connexion, état,
    dernière synchro, webhook URL et secret avec copie, valeurs par défaut, format d’étiquette,
    actions groupées ;
  * *Mapping des statuts* ;
  * *Contrôle des colis* : `GET /parcels` avec filtres, recherche par n° de suivi, produits ;
  * *Webhooks* : événements reçus et en-têtes ;
  * *Journal d’erreurs* : bouton « Réessayer ».
* **Paramètres → Transporteurs → Sift.ma** (`/parametres/transporteurs/sift`), avec un sélecteur
  Ozon / Sift.
* **Commandes** :
  * popup d’expédition et « Envoyer à Sift » groupé (fenêtre de validation) ;
  * colonne Transporteur et suivi « Sift » ;
  * « Étiquettes Sift » groupé, avec choix du format.
* **Fiche commande**, carte Sift.ma :
  * informations : suivi, parcelId, customOrderNo, statut brut et statut mappé, date d’envoi,
    historique ;
  * actions : Actualiser, Modifier (si en attente), Annuler chez le transporteur, Supprimer/masquer,
    Télécharger l’étiquette Sift (format au choix), Resynchroniser.
* **`sift_bulk_enabled`** (table `settings`, `false` par défaut) : tant qu’il est désactivé, l’envoi
  et les étiquettes ne marchent que sur une seule commande à la fois, comme pour Ozon.

## 9. Pour le moteur d’Automatisations (futur)

`App\Services\Sift\SiftShipmentService::forCompany($companyId)` expose :
* `preview()`, `createParcel()`, `createMany()` ;
* `refreshParcel()`, `syncStatus()`, `applyParcel()` ;
* `updateParcel()`, `cancelParcel()`, `hideParcel()` ;
* `waybill()`, `resync()`, `searchProducts()`, `retry()`.

Rien n’est envoyé automatiquement : ces méthodes doivent être appelées explicitement.

## 10. Données (migration additive `2026_10_04_150000_create_sift_tables`)

* `sift_settings` : config par société (clé et secret chiffrés).
* `sift_shipments` : colis, statut brut et mappé, historique, payload et réponses (sans clé), annulation et masquage.
* `sift_webhook_events` : événements (`event_id` unique), statut, en-têtes.
* `sift_api_logs` : erreurs API (rédigées) et relances.

Aucune table existante n’est modifiée. `Setting::DEFAULTS` reçoit `sift_bulk_enabled => false`.

## 11. À vérifier avec la vraie clé (ordre conseillé)

1. *Tester la connexion* : 200 avec la clé (essayer Bearer, puis X-API-Key si besoin).
2. Envoyer **une** commande test : relever dans `sift_shipments.create_response` les noms exacts des
   champs (parcelId, trackingNumber) et ajuster `SiftClient::normaliseParcel` / `build` si besoin.
3. Renvoyer la même commande en vidant `sift_shipments` à la main en local, ou via « Resynchroniser » :
   vérifier comment Sift signale le colis existant.
4. Modifier en statut `pending`, puis vérifier le refus après ramassage.
5. Télécharger l’étiquette dans les 6 formats (nom du paramètre `format`).
6. Annuler chez le transporteur, puis Supprimer/masquer.
7. Brancher le webhook (bouton ou copier/coller), déclencher un changement de statut, puis lire dans
   l’onglet Webhooks l’en-tête de signature réel. Si l’événement est « refusé », ajuster
   `SiftClient::verifySignature`.
8. Vérifier le mapping des statuts reçus.
9. Filtres de *Contrôle des colis* et pagination ; recherche produits.
10. Provoquer une erreur (téléphone invalide), puis « Réessayer ».
11. Vérifier que la clé n’apparaît nulle part (`storage/logs`, `sift_api_logs`).
12. Seulement ensuite : activer « Actions groupées Sift ».
