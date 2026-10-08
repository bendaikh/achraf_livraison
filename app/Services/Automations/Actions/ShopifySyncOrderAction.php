<?php

namespace App\Services\Automations\Actions;

use App\Models\Order;
use App\Services\Shopify\ShopifyFulfillmentService;
use Illuminate\Validation\ValidationException;

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
        $number = (string) ($config['tracking_number'] ?? '');
        $create = (bool) ($config['create_fulfillment'] ?? false);
        $push = (bool) ($config['push_tracking'] ?? false);
        if ($simulate) {
            return [
                'ok' => true,
                'simulated' => true,
                'output' => [
                    'tracking_number' => $number,
                    'create_fulfillment' => $create,
                    'push_tracking' => $push,
                ],
            ];
        }
        if (! $create && ! $push) {
            return ['ok' => true, 'output' => ['skipped' => true]];
        }
        $order = Order::query()->find($context['order']['id'] ?? null);
        if (! $order) {
            return ['ok' => false, 'error' => 'Commande introuvable.'];
        }
        try {
            $row = $push && ! $create
                ? app(ShopifyFulfillmentService::class)->updateTracking($order, $number, $config['tracking_company'] ?? null, $config['tracking_url'] ?? null, (bool) ($config['notify_customer'] ?? false), null)
                : app(ShopifyFulfillmentService::class)->create($order, $number, $config['tracking_company'] ?? null, $config['tracking_url'] ?? null, (bool) ($config['notify_customer'] ?? false), null);
        } catch (ValidationException $e) {
            return ['ok' => false, 'error' => collect($e->errors())->flatten()->first() ?: $e->getMessage()];
        }

        return ['ok' => true, 'output' => ['tracking_number' => $row->tracking_number, 'fulfillment_id' => $row->id]];
    }
}
