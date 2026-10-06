# Automatisations — moteur extensible

Module **Automatisations** (menu après Intégrations) : builder de scénarios
`QUAND → SI → ALORS → ATTENDRE → SI/SINON → ACTION(S)`, isolé par `company_id`.

## Architecture

| Pièce | Rôle |
|--------|------|
| `AutomationRegistry` | Discovery : `registerTrigger` / `registerAction` / `registerConditionField` |
| `AutomationEngine` | Exécute le graphe JSON, propage le contexte (`steps.*`, `vars.*`), gère wait / retry / simulation |
| `AutomationDispatcher` | Pont événements domaine → moteur (sans scénarios hardcodés) |
| `OrderAutomationObserver` | Publie `order.created`, changements de statut/confirmation, etc. |

Les scénarios métier vivent uniquement dans `automations.definition` (JSON), jamais dans des `if/else` métiers hors du moteur.

## Enregistrer un trigger / une action depuis une intégration

Dans le `boot()` d’un service provider (ou un bootstrap dédié) :

```php
use App\Services\Automations\AutomationRegistry;
use App\Services\Automations\Contracts\AutomationAction;

public function boot(AutomationRegistry $registry): void
{
    $registry->registerTrigger('myintegration.event', [
        'label' => 'Mon événement',
        'category' => 'myintegration',
        'config_schema' => [
            ['key' => 'foo', 'label' => 'Foo', 'type' => 'string'],
        ],
    ]);

    $registry->registerConditionField('my_field', [
        'label' => 'Mon champ',
        'type' => 'string',
        'group' => 'myintegration',
        'operators' => ['eq', 'neq', 'contains'],
    ]);

    $registry->registerAction(new class implements AutomationAction {
        public function key(): string { return 'myintegration.do_thing'; }
        public function label(): string { return 'Mon intégration · Faire X'; }
        public function integration(): string { return 'myintegration'; }
        public function configSchema(): array {
            return [['key' => 'param', 'label' => 'Param', 'type' => 'string']];
        }
        public function handle(array $config, array $context, bool $simulate = false): array {
            if ($simulate) {
                return ['ok' => true, 'simulated' => true, 'output' => ['would' => $config]];
            }
            // effets de bord réels…
            return ['ok' => true, 'output' => ['trackingNumber' => 'ABC']];
        }
    });
}
```

Puis publier l’événement :

```php
app(\App\Services\Automations\AutomationDispatcher::class)->dispatch(
    'myintegration.event',
    $companyId,
    $order,                 // sujet morphable (optionnel)
    ['extra' => 'payload'],
    idempotencyKey: 'myintegration:'.$eventId, // anti-doublon
);
```

## Format `definition`

```json
{
  "entry": "if_city",
  "steps": {
    "if_city": {
      "type": "condition",
      "logic": "and",
      "rules": [{ "field": "city", "op": "eq", "value": "Casablanca" }],
      "then": "wait_1",
      "else": null
    },
    "wait_1": {
      "type": "wait",
      "amount": 2,
      "unit": "hours",
      "recheck_conditions": true,
      "conditions": {
        "logic": "and",
        "rules": [{ "field": "confirmation_status", "op": "eq", "value": "no_answer" }]
      },
      "next": "note"
    },
    "note": {
      "type": "action",
      "action": "internal.add_note",
      "config": { "note": "Auto {{order.name}}", "field": "internal_note" },
      "next": null
    }
  }
}
```

Variables : `{{order.city}}`, `{{steps.<step_key>.trackingNumber}}`, `{{vars.trackingNumber}}`.

## Permissions

- `automations.view` — liste, logs, catalogue
- `automations.manage` — CRUD, activer / pause / archiver, retry
- `automations.test` — simulation sur une commande réelle

## Files / jobs

Queue dédiée : `automations`

- `ProcessAutomationRunJob` — démarrage asynchrone d’un run
- `ResumeAutomationWaitJob` — reprise après une étape `wait` (re-vérifie les conditions)

```bash
php artisan queue:work --queue=automations,default
```

## Idempotence

Index unique `(company_id, automation_id, idempotency_key)` sur `automation_runs`.
Le dispatcher compose la clé (`order.created:{id}`, etc.) pour éviter WhatsApp / colis / fulfillments en double.

## Post-déploiement

```bash
php artisan migrate
php artisan db:seed --class=AutomationTemplateSeeder   # si pas déjà via DatabaseSeeder
php artisan queue:work --queue=automations,default
npm run build   # assets React (menu + pages)
```
