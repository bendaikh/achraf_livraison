# MODULE LIVREURS – LAVFAST FLOW

## Objectif

Intégrer directement dans Lavfast Flow un espace dédié aux livreurs locaux.

Ce module fait partie intégrante de Lavfast Flow et utilise exactement les mêmes commandes que l’administration. L’espace livreur est construit dans le système ; il ne s’agit pas d’une application externe, ni d’une duplication des commandes.

Pour cette étape : construire uniquement le **module Livreurs** et l’**espace mobile du livreur**. La logique détaillée de clôture financière sera finalisée dans le module **Clôture du jour**.

---

## 1. Gestion des livreurs

Dans **Livreurs**, l’administrateur doit pouvoir :

* créer un livreur ;
* modifier ses informations ;
* activer / désactiver son compte ;
* voir son téléphone ;
* voir ses commandes attribuées ;
* voir ses commandes en cours ;
* voir ses commandes livrées ;
* voir ses reports / échecs ;
* voir sa caisse et le montant qu’il détient.

Chaque livreur possède son propre accès Lavfast Flow avec le rôle **Livreur**.

---

## 2. Interface spécifique livreur

Lorsqu’un livreur se connecte, il ne doit pas voir l’administration complète.

Il doit avoir une interface **mobile-first**, simple et rapide, contenant principalement :

* Mes livraisons
* Mes ramassages
* Retours / échanges
* Articles en ma possession
* Ma caisse
* Clôture
* Notifications
* Messages avec le responsable

---

## 3. Commandes attribuées

Une commande attribuée par l’administrateur doit apparaître **immédiatement** chez le livreur.

Sur chaque commande afficher :

* numéro de commande ;
* statut ;
* client ;
* téléphone ;
* adresse ;
* ville ;
* produits et quantités ;
* montant à encaisser ;
* notes.

Actions de contact à prévoir :

* **Appeler client**
* **WhatsApp client**
* **Contacter responsable**

---

## 4. Actions du livreur

Le livreur doit pouvoir effectuer :

* Prendre en charge
* Livrée
* Pas de réponse
* Reporter
* Échouée

Les statuts ne doivent **pas** être codés définitivement en dur, afin de pouvoir en ajouter d’autres plus tard.

---

## 5. Reportée

Lorsque le livreur choisit **Reporter**, demander :

* date ;
* heure ;
* commentaire.

La commande reste chez le même livreur jusqu’à décision contraire de l’administrateur.

---

## 6. Livrée et encaissement

Lorsqu’il clique sur **Livrée**, demander / valider le montant réellement encaissé.

Enregistrer :

* livreur ;
* date ;
* heure ;
* montant encaissé.

Une commande marquée livrée par le livreur ne doit **pas** immédiatement être considérée comme financièrement clôturée.

Elle reste en attente de validation / clôture administrateur.

---

## 7. Caisse livreur

Calculer automatiquement :

* COD encaissé ;
* montants déjà remis ;
* frais éventuels ;
* montant restant chez le livreur.

Toutes les opérations doivent être **historisées**, afin que la balance ne puisse pas changer après actualisation ou modification accidentelle.

---

## 8. Articles chez les livreurs

Pouvoir savoir exactement quels articles sont physiquement chez chaque livreur :

* commandes en livraison ;
* échanges ;
* retours récupérés ;
* ramassages.

Le livreur doit également voir cette liste.

---

## 9. Ramassages

Prévoir un vrai workflow de **Ramassage**.

L’administrateur peut affecter un ou plusieurs ramassages à un livreur.

Prévoir les ramassages groupés.

---

## 10. Retours et échanges

Le livreur doit pouvoir gérer :

* récupération retour ;
* échange client ;
* nouvel article remis ;
* ancien article récupéré ;
* retour au dépôt.

Tout doit rester rattaché à la commande d’origine.

---

## 11. Messagerie Admin ↔ Livreur

Ajouter une messagerie interne entre le livreur et le responsable.

Depuis une commande, le livreur doit pouvoir cliquer sur **Contacter responsable**.

Les messages liés à une commande doivent conserver la référence de cette commande.

---

## 12. Notifications

Le livreur doit être notifié lorsqu’il reçoit :

* une nouvelle commande ;
* un ramassage ;
* une modification ;
* un retrait de commande ;
* un message du responsable.

---

## 13. Historique

Toutes les actions du livreur doivent alimenter l’historique central de la commande.

Exemple :

* Affectée à Yassine – 10:15
* Prise en charge – 11:02
* Pas de réponse – 12:14
* Reportée au 26/09 à 15:00
* Livrée – 300 MAD encaissés

---

## 14. Même commande, pas de duplication

**Très important :** l’espace livreur ne doit pas créer une copie des commandes.

Il utilise la même commande Lavfast Flow.

Une modification faite côté livreur doit donc apparaître immédiatement côté administration.

---

## 15. Multi-sociétés

Cette fonctionnalité doit respecter l’architecture multi-sociétés de Lavfast Flow.

Chaque société possède ses propres livreurs et chaque livreur ne doit voir que les données de sa société.

---

# TARIFICATION DES LIVREURS

La tarification fait partie du module Livreurs. Elle doit être construite de façon configurable, afin de pouvoir modifier les prix sans toucher au code.

---

## 1. Tarifs des missions (fiche livreur)

Dans la fiche de chaque livreur, ajouter une section **Tarifs des missions**.

Les tarifs ne doivent **jamais** être codés en dur.

Pour chaque livreur, l’administrateur doit pouvoir définir séparément le prix de :

* Livraison
* Ramassage
* Dépôt partenaire
* Retour
* Échange
* éventuellement plus tard toute nouvelle mission

Exemple :

| Mission          | Tarif   |
|------------------|---------|
| Livraison        | 20 DH   |
| Ramassage        | 10 DH   |
| Dépôt partenaire | 6 DH    |
| Retour / échange | 7 DH    |

Ce sont des tarifs configurables et modifiables depuis la fiche du livreur.

---

## 2. Tarif par défaut + tarif individuel

Prévoir également dans **Paramètres** des tarifs par défaut de la société.

Exemple :

* Livraison = 20 DH
* Ramassage = 10 DH

Lorsqu’un nouveau livreur est créé, ces tarifs sont proposés automatiquement.

L’administrateur peut ensuite appliquer un tarif différent à un livreur précis.

---

## 3. Conserver le tarif historique

**Très important :** lorsqu’une mission est attribuée à un livreur, enregistrer le tarif applicable à cette mission **à ce moment-là**.

Exemple :

* Aujourd’hui Yassine est payé 20 DH par livraison.
* Demain son tarif passe à 25 DH.
* Les anciennes livraisons restent calculées à **20 DH**.
* Uniquement les nouvelles missions utilisent **25 DH**.

Une modification de tarif ne doit **jamais** recalculer rétroactivement l’historique.

---

## 4. Calcul des gains

Lavfast Flow doit calculer automatiquement :

```
Nombre de livraisons × tarif livraison
+ nombre de ramassages × tarif ramassage
+ dépôts partenaires
+ retours / échanges
= Total à payer au livreur
```

Ce total doit être **séparé** du COD encaissé pour le compte de la société.

Exemple :

* Le livreur encaisse 1 500 DH auprès des clients.
* Ses commissions de missions = 120 DH.

Le système doit distinguer clairement :

| Élément                         | Montant   |
|---------------------------------|-----------|
| COD encaissé                    | 1 500 DH  |
| Rémunération livreur            | 120 DH    |
| Montant à remettre à la société | selon les règles de clôture |

**Ne jamais mélanger** la caisse du livreur (COD) avec ses commissions.

---

## 5. Historique des tarifs

Conserver pour chaque changement :

* ancien tarif ;
* nouveau tarif ;
* date du changement ;
* utilisateur ayant effectué le changement.

---

## 6. Interface

Conserver la présentation avec les différents types de missions et le montant en DH, en ajoutant la possibilité de modifier facilement chaque tarif puis **Enregistrer les montants**.

Prévoir également la possibilité d’ajouter plus tard un nouveau type de mission **sans refaire toute la page** (types de missions configurables / extensibles).

---

## Périmètre de cette étape

| Inclus                                      | Reporté au module Clôture du jour      |
|---------------------------------------------|----------------------------------------|
| Module Livreurs (admin)                     | Logique détaillée de clôture financière |
| Espace mobile livreur                       | Règles finales de remise société       |
| Tarification configurable + historique      |                                        |
| Caisse livreur (solde / COD historisé)      |                                        |
| Messagerie, notifications, historique cmd   |                                        |
