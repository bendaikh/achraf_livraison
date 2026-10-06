<?php

namespace App\Services\Automations\Actions;

class ShopifySyncOrderAction extends BaseAction
{
    public function key(): string
    {
        return 'shopify.sync_order';
    }

    public function label(): string
    {
        return 'Shopify · Synchroniser la commande';
    }

    public function integration(): string
    {
        return 'shopify';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'push_tracking', 'label' => 'Pousser le tracking', 'type' => 'boolean', 'default' => false],
            ['key' => 'create_fulfillment', 'label' => 'Créer fulfillment', 'type' => 'boolean', 'default' => false],
            ['key' => 'tracking_number', 'label' => 'N° tracking', 'type' => 'string', 'hint' => '{{vars.trackingNumber}}'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        return $this->stub($config, $simulate, 'Shopify sync_order (stub)');
    }
}
