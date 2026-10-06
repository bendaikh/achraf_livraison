<?php

namespace App\Services\Automations\Actions;

class WebhookExternalAction extends BaseAction
{
    public function key(): string
    {
        return 'internal.webhook';
    }

    public function label(): string
    {
        return 'Appeler un webhook externe';
    }

    public function integration(): string
    {
        return 'internal';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'url', 'label' => 'URL', 'type' => 'string', 'required' => true],
            ['key' => 'method', 'label' => 'Méthode', 'type' => 'select', 'options' => [
                ['value' => 'POST', 'label' => 'POST'],
                ['value' => 'GET', 'label' => 'GET'],
            ], 'default' => 'POST'],
            ['key' => 'body', 'label' => 'Corps (JSON)', 'type' => 'textarea'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        if ($simulate) {
            return $this->stub($config, true, 'Webhook externe (simulation)');
        }

        // Real HTTP call deferred — register a proper client from an integration package.
        return $this->stub($config, false, 'Webhook externe (stub — brancher HTTP client)');
    }
}
