# Intégration Ozon Express (T14)

Transporteur « Ozon Express » branché dans le registre des sociétés de livraison
(`config/carriers.php` → `App\Services\Carriers\OzonCarrier`, interface `CarrierInterface` +
`AdvancedCarrier`). La livraison locale, Speedaf et les autres transporteurs restent inchangés.

## 1. Sources

Ozon Express ne publie **pas** de documentation API publique en ligne (recherche du 04/10/2026 :
aucune page sur ozonexpress.ma, pas de Swagger/Postman public). Les informations ci-dessous viennent :

* **d’appels réels** (endpoints publics / sans identifiants), faits le 04/10/2026 ;
* **de clients open source** qui utilisent l’API (recoupés entre eux) :
  D4azai/projectpfa (`OZONE_EXPRESS_API.md`), aymane-razik/Stockwise (`OzonExpressApiClient.cs`),
  Vazimax (`ozonexpress.py`), ecomos-ma/Ecom-Os, Mbourza/obgecom, AbdoHerO (`OzonExpressService.php`),
  djeytkey/cities.

Légende : ✅ confirmé par un appel réel · 🟡 concordant dans plusieurs clients, pas encore vérifié
avec nos identifiants · ❓ incertain.

## 2. Connexion

| Élément | Valeur | Niveau |
|---|---|---|
| Base | `https://api.ozonexpress.ma/customers/{ID}/{KEY}/` | ✅ (réponse `CHECK_API` reçue) |
| Méthode | `POST` multipart/form-data | 🟡 |
| Identifiants invalides | HTTP **200** + `{"CHECK_API":{"RESULT":"ERROR","MESSAGE":"Please verify your API Key"}}` | ✅ |
| Endpoint « test » dédié | aucun : « Tester la connexion » appelle `tracking` avec un numéro fictif et lit `CHECK_API` | ✅ |

**Clé API** : stockée chiffrée (`ozon_settings.api_key`, cast `encrypted`, `$hidden`), jamais
renvoyée au navigateur (le formulaire affiche seulement `••••••••xxxx`), jamais écrite dans les logs :
`OzonClient::redact()` remplace la clé et le segment `/customers/{id}/{clé}` par `/customers/{id}/••••`
dans les messages d’exception, `ozon_api_logs`, `ozon_shipments.*_response`, `ozon_delivery_notes.responses`
et `laravel.log` (testé : `assertNoKeyAnywhere`).

## 3. Endpoints

### Villes — `GET https://api.ozonexpress.ma/cities` ✅ (public)
```json
{"CITIES":{"37":{"ID":37,"REF":"AGA","NAME":"Agadir","DELIVERED-PRICE":35,"RETURNED-PRICE":0,"REFUSED-PRICE":10}, …},"DEBUG":null}
```
801 villes au 04/10/2026. Certains noms contiennent des tabulations / espaces multiples (nettoyés).
**Pas de « Casablanca » seul** : uniquement des quartiers (« Casablanca – Maarif », « Casablanca – Anfa »…,
tiret demi-cadratin) → Casablanca doit être associée **manuellement** (Paramètres → Transporteurs → Ozon → Mapping villes).
Import : bouton « Synchroniser les villes » ou `php artisan ozon:cities` (+ planifié chaque lundi 03:15).

### Créer un colis — `POST add-parcel` 🟡
Champs envoyés (form-data) :

| Champ | Source Lav’Fast Flow |
|---|---|
| `tracking-number` | **non envoyé** (optionnel ; Ozon génère le numéro) |
| `parcel-receiver` | nom client (ou client de la demande SAV) |
| `parcel-phone` | téléphone normalisé `06…` |
| `parcel-city` | **ID** Ozon via le mapping villes (jamais le texte) |
| `parcel-address` | adresse de livraison |
| `parcel-note` | note de la commande (option « Envoyer la note ») |
| `parcel-price` | COD : total si paiement à la livraison, sinon 0 ; saisi pour un échange SAV |
| `parcel-nature` | liste produits (« 1× Robe L, … ») ou texte fixe (paramètre) |
| `parcel-stock` | 1 = stock Ozon, 0 = ramassage : défaut société + surcharge par type (livraison / échange) |
| `parcel-open` | 1 = oui, 2 = non : défaut société, modifiable dans la fenêtre de validation |
| `parcel-fragile` | 1 / 0 : défaut société, modifiable |
| `parcel-replace` | 1 automatiquement quand l’envoi vient du module Retours & échanges (échange), sinon 0 |
| `products` | JSON `[{"ref":"SKU","qnty":2}]`, **seulement les lignes avec SKU** (aucune réf inventée) ; omis si aucune |

Réponse attendue :
```json
{"CHECK_API":{"RESULT":"SUCCESS"},
 "ADD-PARCEL":{"RESULT":"SUCCESS","MESSAGE":"…","NEW-PARCEL":{
   "TRACKING-NUMBER":"…","RECEIVER":"…","PHONE":"…","CITY_ID":"…","CITY_NAME":"…","ADDRESS":"…",
   "PRICE":"…","DELIVERED-PRICE":"…","RETURNED-PRICE":"…","REFUSED-PRICE":"…"}}}
```
Tout est enregistré sur `ozon_shipments` (+ réponse brute expurgée dans `create_response`).

### Infos colis — `POST parcel-info` (`tracking-number`) 🟡
Réponse `PARCEL-INFO.INFOS{…}` (mêmes clés que NEW-PARCEL). Bouton « Actualiser depuis Ozon ».

### Suivi — `POST tracking` (`tracking-number`) 🟡
```json
{"TRACKING":{"RESULT":"SUCCESS","TRACKING-NUMBER":"…",
  "LAST_TRACKING":{"STATUT":"Livré","COMMENT":"…","TIME":1759600000,"TIME_STR":"2026-10-04 18:30"},
  "HISTORY":{"1":{"STATUT":"…","COMMENT":"…","TIME_STR":"…"}, …}}}
```
### Suivi groupé — `POST tracking` avec corps JSON `{"tracking-number":["A","B",…]}` ❓
Format de requête vu dans un seul client. **Forme de la réponse inconnue** : le parseur accepte une
liste ou un objet indexé par numéro ; si la réponse n’est pas exploitable, repli automatique sur des
appels unitaires. Lots de 50. Planifié toutes les 30 min (`Schedule::call`, désactivable : « Synchronisation automatique »)
+ bouton « Synchroniser les statuts » + `php artisan ozon:sync`.

### Bons de livraison (BL) 🟡
1. `POST add-delivery-note` → `ADD-BL.NEW-BL{REF, ID}`
2. `POST add-parcel-to-delivery-note` : `Ref`, `Codes[0]`, `Codes[1]`… → bloc `ADD-PARCEL-BL`
3. `POST save-delivery-note` : `Ref` → bloc `SAVE-BL`

Reprise : un BL qui échoue garde sa ref et son état (`created` / `filled`) ; « Réessayer » dans le journal reprend à l’étape manquante.

### Documents du BL ❓ (URL construites à partir de la ref)
* BL PDF : `https://client.ozoneexpress.ma/pdf-delivery-note?dn-ref={REF}`
* Étiquettes A4 : `https://client.ozoneexpress.ma/pdf-delivery-note-tickets?dn-ref={REF}`
* Étiquettes 10×10 : `https://client.ozoneexpress.ma/pdf-delivery-note-tickets-4-4?dn-ref={REF}`

Attention au domaine **ozon*e*express** (avec un « e ») pour l’espace client. Inconnu : ces liens
demandent-ils une session ouverte sur l’espace client Ozon ? (sinon : proxy serveur à prévoir).
Le client AbdoHerO utilise d’autres chemins (`dn-create`, `dn-pdf`) qui semblent inventés : ignorés.

## 4. Statuts Ozon ❓
Statuts vus dans les clients (liste non exhaustive, orthographe variable) : Nouveau Colis, En attente de
ramassage / Attente De Ramassage, Ramassé, Reçu / Colis Reçu, En transit, En cours de livraison / En Livraison,
Livré, Refusé, Retourné, Annulé, Pas de réponse, Reporté, Échec de livraison, non soumis, En traitement.

Le statut **brut** est toujours conservé (`ozon_shipments.raw_status`, `raw_status_comment`, `history`).
Le passage au statut Lav’Fast Flow suit la table **configurable** (Intégrations → Ozon Express → Mapping des statuts) :
correspondance exacte (insensible à la casse/accents), puis familles (« livr » → Livrée, « retour » → Retour…).
Les statuts reçus inconnus sont ajoutés à la table (badge « reçu ») pour être associés.
Les colis d’échange SAV ne changent jamais le statut de la commande (déjà livrée).

## 5. Modèle de données (migration additive `2026_10_04_100000_create_ozon_tables`)
`ozon_settings`, `ozon_cities`, `ozon_city_mappings`, `ozon_shipments`, `ozon_delivery_notes`,
`ozon_delivery_note_items`, `ozon_api_logs`. Aucune table existante modifiée. Paramètre
`ozon_bulk_enabled` (table `settings`, défaut **false**).

## 6. Fonctionnement côté application
* **Commandes** : colonne Transporteur = « Ozon Express », Suivi = numéro + statut brut. Popup « Expédier » par ligne
  et action groupée « Envoyer à Ozon Express » ouvrent la **fenêtre de validation** (client, ville → ville Ozon,
  COD, ouverture, fragile, échange, stock, produits) → « Confirmer l’envoi ». Ville non associée : association
  directe depuis la fenêtre (droit Paramètres).
* **Anti-doublon** : commande avec un suivi Ozon actif → « Cette commande est déjà envoyée à Ozon », liens
  « Ouvrir l’envoi existant » / « Actualiser depuis Ozon ». Contrôle aussi côté serveur (verrou par commande).
* **Actions groupées** « Envoyer à Ozon », « Créer BL Ozon », « Étiquettes Ozon » : désactivées tant que
  `ozon_bulk_enabled` = false (UI + API, 422). L’envoi d’une seule commande reste possible.
* **Fiche commande** : carte Ozon Express (suivi, statut brut + statut Lav’Fast, ville, frais, options, historique,
  BL + PDF, « Actualiser depuis Ozon », « Vérifier le suivi », « Créer BL Ozon »).
* **Retours & échanges** : « Envoyer à Ozon » sur une demande d’échange (`parcel-replace=1`).
* **Historique** (timeline commande, `kind = ozon`) : envoyée, suivi créé, statut mis à jour, ajoutée au BL,
  BL enregistré — avec l’utilisateur, ou « Système » pour la synchro planifiée.
* **Journal d’erreurs** : endpoint, commande, date, message, utilisateur, payload sans clé, « Réessayer ».
* **Automatisations (à venir)** : `OzonShipmentService::createParcel()`, `refreshTracking()`,
  `addToDeliveryNote()`, `syncStatus()` sont publiques ; aucun envoi automatique n’est branché.

## 7. Plan de test avec les vrais identifiants
1. Intégrations → Ozon Express : saisir ID client + clé, activer, Enregistrer, **Tester la connexion** → « Connecté ».
2. « Synchroniser les villes » (801 villes), puis Mapping villes : « Associer automatiquement », associer
   à la main Casablanca (quartier) et les villes « À associer ».
3. Une commande test **confirmée** : Expédier → Ozon Express → vérifier la fenêtre → Confirmer l’envoi.
   Vérifier dans l’espace client Ozon que le colis existe (ville, COD, ouverture, fragile, produits) et que
   la fiche commande affiche numéro, ville, frais livré/retourné/refusé.
4. « Actualiser depuis Ozon » (parcel-info) puis « Vérifier le suivi » (tracking).
5. Relever les statuts bruts reçus et compléter le Mapping des statuts.
6. « Créer BL Ozon » sur cette commande → ref affichée ; ouvrir BL PDF, Étiquettes A4 et 10×10
   (connecté puis **déconnecté** de l’espace client Ozon, pour savoir si un proxy est nécessaire).
7. « Synchroniser les statuts » avec au moins 2 colis : si le suivi groupé n’est pas compris, l’app repasse
   en appels unitaires et écrit un avertissement « Ozon bulk tracking » avec un extrait de la réponse
   (sans clé) dans `storage/logs/laravel.log` → adapter `OzonClient::trackingBulk` à la vraie forme.
8. Provoquer une erreur (ville d’un autre pays / téléphone invalide) → journal d’erreurs + « Réessayer ».
9. Échange SAV : demande d’échange → « Envoyer à Ozon » → `parcel-replace = 1`, statut de la commande inchangé.
10. Vérifier `storage/logs/laravel.log` et `ozon_api_logs` : la clé n’apparaît nulle part.
11. Seulement ensuite : activer « Actions groupées Ozon ».
