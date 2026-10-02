# Intégration Speedaf

Source : « Speedaf Open API » (PDF fourni par Speedaf, endpoints v2).

## API Speedaf (résumé)

| | |
|---|---|
| Production | `https://apis.speedaf.com/` |
| Test (UAT) | `https://uat-api.speedaf.com/` (appCode/customerCode public Maroc : `MA000025`) |
| Auth | `?appCode=XXX&timestamp=<ms>` sur chaque appel (timestamp expiré → 70502). Pas de signature sur les requêtes dans cette version de la doc. |
| Format | `POST`, corps JSON `{"data": ...}` ; réponse `{"success", "error": {"code","message"}, "data"}` |
| secretKey | Uniquement pour vérifier les webhooks : `X-Speedaf-Signature: hmac-sha256=` + HMAC-SHA256(secretKey, timestamp + "\n" + corps brut) |

Endpoints utilisés : `express/order/v2/createOrder`, `cancelOrder`, `updateOrder` (client seulement), `print` (étiquette PDF),
`express/track/v2/query` (suivi), `express/track/webhook/subscribe` (webhook), `common/area/v2/new/getArea` (test de connexion),
`common/area/v2/getTreeByCountryCode` (ville → région), `fee/v2/getFee` (client seulement).

## Configuration (Intégrations → Speedaf)

1. Environnement, App Code, Code client, Platform source (fournis par Speedaf), clé secrète (webhook, chiffrée en base).
2. Expéditeur / ramassage (nom, téléphone, adresse, ville ; région détectée automatiquement).
3. Options par défaut (PT01/DE01/TT01/ST01/PA02, type de marchandise, poids par défaut, format d’étiquette 2/5/46).
4. « Tester la connexion », puis « Enregistrer le webhook chez Speedaf » (URL publique HTTPS en production).
5. Correspondance des statuts Speedaf → statuts Lavfast.

## Commandes

- Multi-sélection → « Envoyer à Speedaf » (50 max), « Étiquettes Speedaf ».
- Fiche commande → carte Speedaf : n° de suivi, statut, imprimer l’étiquette, actualiser, annuler.
- Filtre « Speedaf : envoyées / non envoyées », colonne « Suivi Speedaf ».
- Seules les commandes confirmées (et non livrées / annulées / retournées) sont envoyables.

## Synchronisation

- Webhook : `POST /speedaf/webhook/{token}` (signature HMAC vérifiée si la clé secrète est renseignée, fenêtre 5 min, idempotent sur eventId).
- Planifié : `speedaf:sync` toutes les 30 min via `Schedule::call` (in-process, pas de proc_open).
  Cron Hostinger : `* * * * * /usr/bin/php /chemin/artisan schedule:run >> /dev/null 2>&1`.

## Tables

`speedaf_settings` (par société), `speedaf_shipments` (n° de suivi, payload/réponses brutes, suivi), `speedaf_webhook_events`.
