# WhatsApp Campaigns

Module **séparé** des Automatisations. Il réutilise les comptes WhatsApp, templates Meta, clients (`phone_key`), groupes, et ajoute tags, véhicules (feature flag), consentement marketing, segments dynamiques et une file d’envoi dédiée.

## Architecture

| Couche | Emplacement |
|--------|-------------|
| Tables | `whatsapp_campaigns`, `whatsapp_campaign_recipients`, `client_tags`, `client_tag_assignments`, `client_vehicles`, `client_whatsapp_consents`, `client_audience_segments` |
| Audience | `App\Services\Campaigns\AudienceQueryBuilder` |
| Tags | `App\Services\Campaigns\TagService` (réutilisé par Automations `client.add_tag` / `client.remove_tag`) |
| Consentement | `App\Services\Campaigns\ConsentService` |
| Envoi | `App\Services\Campaigns\CampaignSender` (+ fake en `testing` / `CAMPAIGNS_FAKE_SENDER`) |
| Lancement | `App\Services\Campaigns\CampaignLauncher` → queue `whatsapp-campaigns` |
| Statuts Meta | `WebhookProcessor` → `CampaignStatusUpdater` via `wa_message_id` |
| UI | WhatsApp → Campagnes (`/whatsapp/campagnes`) |

Permissions : `campaigns.view`, `campaigns.manage`, `campaigns.send`, `clients.tags`.

## Ajouter un critère d’audience

1. Étendre `AudienceQueryBuilder::applyRule()` avec un nouveau `type`.
2. Documenter la forme JSON dans le docblock de la classe.
3. Ajouter l’option dans `CampaignWizard.jsx` (étape Destinataires).
4. Couvrir par un Feature test dans `WhatsAppCampaignsTest`.

Exemple de règle :

```json
{ "type": "vehicle", "brand": "Peugeot", "model": "208", "year_min": 2020, "year_max": 2025 }
```

Un client match si **au moins un** de ses véhicules satisfait toutes les conditions véhicule du groupe.

## Feature véhicules

Sur `companies.features` :

```json
{ "client_vehicles": true }
```

Sinon la section Véhicules et les filtres véhicule sont masqués / refusés (403).

## Réglages campagne (companies.campaign_settings)

- `exclude_refused_consent` (défaut `true`)
- `require_allowed_consent_for_marketing` (défaut `false`)
- `max_campaigns_per_client` / `max_campaigns_window_days`
- `rate_limit_per_minute`, `batch_size`
- `fake_sender`

Timezone société : `companies.timezone` (défaut `Africa/Casablanca`).

## Post-déploiement

```bash
php artisan migrate
# optionnel démo :
php artisan db:seed --class=WhatsAppCampaignDemoSeeder
```

Le worker `schedule:run` (Hostinger) draine déjà `whatsapp-campaigns,automations,default` et lance `campaigns:dispatch-scheduled` chaque minute.

Vérifier que les rôles ont les nouvelles abilities (`config/permissions.php` ou override `role_permissions`).

## Isolation multi-sociétés

Toutes les requêtes campagnes / tags / véhicules / consentements / segments filtrent `company_id`. Les destinataires sont snapshotés au lancement ; l’idempotence empêche un second envoi pour le même `(campaign_id, phone_key)`.
