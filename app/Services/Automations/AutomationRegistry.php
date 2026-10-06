<?php

namespace App\Services\Automations;

use App\Services\Automations\Contracts\AutomationAction;
use InvalidArgumentException;

/**
 * Extensible discovery registry for triggers, condition fields and actions.
 * Integrations call register* at boot (see RegisterBuiltinAutomations) without touching the engine core.
 */
class AutomationRegistry
{
    /** @var array<string, array{key: string, label: string, category?: string, config_schema?: array, description?: string}> */
    protected array $triggers = [];

    /** @var array<string, array{key: string, label: string, type: string, operators?: list<string>, options?: array, group?: string}> */
    protected array $conditionFields = [];

    /** @var array<string, AutomationAction> */
    protected array $actions = [];

    public function registerTrigger(string $key, array $meta): self
    {
        $this->triggers[$key] = array_merge(['key' => $key, 'label' => $key], $meta);

        return $this;
    }

    public function registerConditionField(string $key, array $meta): self
    {
        $this->conditionFields[$key] = array_merge([
            'key' => $key,
            'label' => $key,
            'type' => 'string',
            'operators' => ['eq', 'neq', 'contains', 'empty', 'not_empty'],
        ], $meta);

        return $this;
    }

    public function registerAction(AutomationAction $action): self
    {
        $this->actions[$action->key()] = $action;

        return $this;
    }

    /** @return array<string, array> */
    public function triggers(): array
    {
        return $this->triggers;
    }

    public function trigger(string $key): ?array
    {
        return $this->triggers[$key] ?? null;
    }

    /** @return array<string, array> */
    public function conditionFields(): array
    {
        return $this->conditionFields;
    }

    public function conditionField(string $key): ?array
    {
        return $this->conditionFields[$key] ?? null;
    }

    /** @return array<string, AutomationAction> */
    public function actions(): array
    {
        return $this->actions;
    }

    public function action(string $key): ?AutomationAction
    {
        return $this->actions[$key] ?? null;
    }

    public function requireAction(string $key): AutomationAction
    {
        $action = $this->action($key);
        if (! $action) {
            throw new InvalidArgumentException("Unknown automation action [{$key}].");
        }

        return $action;
    }

    /** Catalog payload for the SPA builder. */
    public function catalog(): array
    {
        return [
            'triggers' => array_values($this->triggers),
            'condition_fields' => array_values($this->conditionFields),
            'actions' => array_values(array_map(fn (AutomationAction $a) => [
                'key' => $a->key(),
                'label' => $a->label(),
                'integration' => $a->integration(),
                'config_schema' => $a->configSchema(),
            ], $this->actions)),
            'operators' => [
                ['key' => 'eq', 'label' => 'égal à'],
                ['key' => 'neq', 'label' => 'différent de'],
                ['key' => 'contains', 'label' => 'contient'],
                ['key' => 'not_contains', 'label' => 'ne contient pas'],
                ['key' => 'gt', 'label' => 'supérieur à'],
                ['key' => 'gte', 'label' => 'supérieur ou égal'],
                ['key' => 'lt', 'label' => 'inférieur à'],
                ['key' => 'lte', 'label' => 'inférieur ou égal'],
                ['key' => 'empty', 'label' => 'est vide'],
                ['key' => 'not_empty', 'label' => 'n’est pas vide'],
                ['key' => 'in', 'label' => 'parmi'],
                ['key' => 'not_in', 'label' => 'pas parmi'],
            ],
            'wait_units' => [
                ['key' => 'minutes', 'label' => 'Minutes'],
                ['key' => 'hours', 'label' => 'Heures'],
                ['key' => 'days', 'label' => 'Jours'],
                ['key' => 'until', 'label' => 'Date / heure précise'],
            ],
        ];
    }
}
