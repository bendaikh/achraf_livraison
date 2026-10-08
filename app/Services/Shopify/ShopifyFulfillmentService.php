<?php

namespace App\Services\Shopify;

use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Creates and updates Shopify fulfillments. Nothing here runs because a carrier created a parcel. */
class ShopifyFulfillmentService
{
    public const RECONNECT = 'Nouvelles autorisations Shopify nécessaires — Reconnecter Shopify';

    public function __construct(protected ShopifySyncLogger $logger) {}

    public function create(Order $order, string $number, ?string $company, ?string $url, bool $notify, ?User $user): OrderFulfillment
    {
        $shop = $this->shop($order);
        $client = $this->client($shop);
        $replay = [
            'op' => 'fulfillment', 'order_id' => $order->id, 'number' => $number,
            'company' => $company, 'url' => $url, 'notify' => $notify, 'user_id' => $user?->id,
        ];

        try {
            $looked = $client->graphql(self::FULFILLMENT_ORDERS, [
                'id' => "gid://shopify/Order/{$order->shopify_order_id}",
            ]);
            $foNodes = data_get($looked, 'order.fulfillmentOrders.nodes', []);
            $open = [];
            foreach (is_array($foNodes) ? $foNodes : [] as $node) {
                $actions = array_map(
                    fn ($action) => is_array($action) ? ($action['action'] ?? null) : $action,
                    $node['supportedActions'] ?? [],
                );
                if (in_array('CREATE_FULFILLMENT', $actions, true)) {
                    $open[] = $node;
                }
            }
            if ($open === []) {
                throw new ShopifyApiException('Aucune ligne à expédier sur Shopify pour cette commande', 422, false);
            }
            $created = $client->graphqlMutation(self::CREATE, [
                'fulfillment' => [
                    'lineItemsByFulfillmentOrder' => array_map(
                        fn ($node) => ['fulfillmentOrderId' => $node['id']],
                        $open,
                    ),
                    'trackingInfo' => array_filter([
                        'number' => $number,
                        'company' => $company,
                        'url' => $url,
                    ], fn ($v) => $v !== null && $v !== ''),
                    'notifyCustomer' => $notify,
                ],
            ], 'fulfillmentCreate');
        } catch (ShopifyApiException $e) {
            $this->fail($shop, $order, $replay, $e, $user);
            throw ValidationException::withMessages(['shopify' => $e->getMessage()]);
        }

        $node = $created['fulfillmentCreate']['fulfillment'] ?? [];
        $gid = (string) ($node['id'] ?? '');
        $numeric = str_contains($gid, '/') ? (int) substr($gid, strrpos($gid, '/') + 1) : (int) $gid;
        $info = $node['trackingInfo'][0] ?? [];

        $row = OrderFulfillment::updateOrCreate(
            ['order_id' => $order->id, 'shopify_fulfillment_id' => $numeric ?: null],
            [
                'company_id' => $shop->resolveCompanyId(),
                'status' => isset($node['status']) ? strtolower((string) $node['status']) : 'success',
                'tracking_number' => $info['number'] ?? $number,
                'tracking_company' => $info['company'] ?? $company,
                'tracking_url' => $info['url'] ?? $url,
                'source' => 'flow',
                'shopify_synced_at' => now(),
            ],
        );
        $order->forceFill([
            'shopify_sync_status' => 'synced',
            'shopify_sync_error' => null,
            'shopify_synced_at' => now(),
        ])->save();
        $this->logger->log([
            'company_id' => $shop->resolveCompanyId(),
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'fulfillment',
            'entity_id' => $order->id,
            'shopify_id' => (string) $order->shopify_order_id,
            'action' => 'fulfillment',
            'source' => 'flow_user',
            'user_id' => $user?->id,
            'status' => 'success',
            'request_excerpt' => $replay,
        ]);

        return $row;
    }

    public function updateTracking(Order $order, string $number, ?string $company, ?string $url, bool $notify, ?User $user): OrderFulfillment
    {
        $existing = $order->fulfillments()->whereNotNull('shopify_fulfillment_id')->latest('id')->first();
        if (! $existing) {
            return $this->create($order, $number, $company, $url, $notify, $user);
        }
        $shop = $this->shop($order);
        $replay = ['op' => 'tracking', 'order_id' => $order->id, 'number' => $number, 'user_id' => $user?->id];
        try {
            $this->client($shop)->graphqlMutation(self::UPDATE, [
                'fulfillmentId' => "gid://shopify/Fulfillment/{$existing->shopify_fulfillment_id}",
                'trackingInfoInput' => array_filter([
                    'number' => $number, 'company' => $company, 'url' => $url,
                ], fn ($v) => $v !== null && $v !== ''),
                'notifyCustomer' => $notify,
            ], 'fulfillmentTrackingInfoUpdate');
        } catch (ShopifyApiException $e) {
            $this->fail($shop, $order, $replay, $e, $user);
            throw ValidationException::withMessages(['shopify' => $e->getMessage()]);
        }
        $existing->forceFill([
            'tracking_number' => $number,
            'tracking_company' => $company,
            'tracking_url' => $url,
            'shopify_synced_at' => now(),
        ])->save();

        return $existing->fresh();
    }

    public function replay(array $payload, ?User $user): void
    {
        $order = Order::query()->findOrFail($payload['order_id']);
        $user ??= ($payload['user_id'] ?? null) ? User::query()->find($payload['user_id']) : null;
        if (($payload['op'] ?? '') === 'tracking') {
            $this->updateTracking($order, (string) $payload['number'], $payload['company'] ?? null, $payload['url'] ?? null, (bool) ($payload['notify'] ?? false), $user);

            return;
        }
        $this->create($order, (string) $payload['number'], $payload['company'] ?? null, $payload['url'] ?? null, (bool) ($payload['notify'] ?? false), $user);
    }

    protected function shop(\App\Models\Order $order): \App\Models\ShopifyShop
    {
        $shop = $order->shop;
        if (! $order->shopify_order_id || ! $shop) {
            throw ValidationException::withMessages(['shopify' => 'Cette commande n’est pas liée à Shopify.']);
        }
        if (! ($shop->capabilities()['fulfillments_write'] ?? false)) {
            throw ValidationException::withMessages(['shopify' => self::RECONNECT]);
        }

        return $shop;
    }

    protected function client(\App\Models\ShopifyShop $shop): ShopifyClient
    {
        return new ShopifyClient($shop->shop_domain, $shop->access_token, app(ShopifyOAuth::class)->apiVersion());
    }

    /** @param  array<string, mixed>  $replay */
    protected function fail(\App\Models\ShopifyShop $shop, Order $order, array $replay, ShopifyApiException $e, ?User $user): void
    {
        $order->forceFill([
            'shopify_sync_status' => 'failed',
            'shopify_sync_error' => mb_substr($e->getMessage(), 0, 500),
        ])->save();
        $this->logger->log([
            'company_id' => $shop->resolveCompanyId(),
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'fulfillment',
            'entity_id' => $order->id,
            'shopify_id' => (string) $order->shopify_order_id,
            'action' => 'fulfillment',
            'source' => 'flow_user',
            'user_id' => $user?->id,
            'status' => 'failed',
            'error' => $e->getMessage(),
            'attempts' => 1,
            'request_excerpt' => $replay,
        ]);
    }

    private const FULFILLMENT_ORDERS = <<<'GQL'
query FulfillmentOrders($id: ID!) {
  order(id: $id) {
    fulfillmentOrders(first: 10) {
      nodes { id status supportedActions { action } }
    }
  }
}
GQL;

    private const CREATE = <<<'GQL'
mutation fulfillmentCreate($fulfillment: FulfillmentInput!) {
  fulfillmentCreate(fulfillment: $fulfillment) {
    fulfillment { id status trackingInfo { number company url } }
    userErrors { field message }
  }
}
GQL;

    private const UPDATE = <<<'GQL'
mutation fulfillmentTrackingInfoUpdate($fulfillmentId: ID!, $trackingInfoInput: FulfillmentTrackingInput!, $notifyCustomer: Boolean) {
  fulfillmentTrackingInfoUpdate(fulfillmentId: $fulfillmentId, trackingInfoInput: $trackingInfoInput, notifyCustomer: $notifyCustomer) {
    fulfillment { id status trackingInfo { number company url } }
    userErrors { field message }
  }
}
GQL;
}
