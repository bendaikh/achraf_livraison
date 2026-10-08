<?php

namespace App\Services\Automations\Actions;

use App\Models\Order;
use App\Services\Shopify\ShopifyFulfillmentService;
use Illuminate\Validation\ValidationException;

class ShopifyCreateFulfillmentAction extends BaseAction
{
    public function key(): string
    {
        return 'shopify.create_fulfillment';
    }

    public function label(): string
    {
        return 'Shopify · Créer le fulfillment';
    }

    public function integration(): string
    {
        return 'shopify';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'tracking_number', 'label' => 'N° tracking', 'type' => 'string', 'hint' => '{{vars.trackingNumber}}'],
            ['key' => 'tracking_company', 'label' => 'Transporteur', 'type' => 'string'],
            ['key' => 'tracking_url', 'label' => 'URL de suivi', 'type' => 'string'],
            ['key' => 'notify_customer', 'label' => 'Notifier le client', 'type' => 'boolean', 'default' => false],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        $number = (string) ($config['tracking_number'] ?? '');
        if ($simulate) {
            return ['ok' => true, 'simulated' => true, 'output' => ['tracking_number' => $number]];
        }
        $order = Order::query()->find($context['order']['id'] ?? null);
        if (! $order) {
            return ['ok' => false, 'error' => 'Commande introuvable.'];
        }
        try {
            $row = app(ShopifyFulfillmentService::class)->create(
                $order,
                $number,
                $config['tracking_company'] ?? null,
                $config['tracking_url'] ?? null,
                (bool) ($config['notify_customer'] ?? false),
                null,
            );
        } catch (ValidationException $e) {
            return ['ok' => false, 'error' => collect($e->errors())->flatten()->first() ?: $e->getMessage()];
        }

        return ['ok' => true, 'output' => ['tracking_number' => $row->tracking_number, 'fulfillment_id' => $row->id]];
    }
}
