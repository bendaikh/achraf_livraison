<?php

namespace App\Services\Shopify;

use App\Models\ShopifyShop;

/** Turns an Admin GraphQL order node into the REST-shaped array webhooks already use. */
class ShopifyOrderNormalizer
{
    public const ORDERS_QUERY = <<<'GQL'
query ReconcileOrders($first: Int!, $after: String, $query: String) {
  orders(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
    pageInfo { hasNextPage endCursor }
    nodes {
      legacyResourceId name email phone note tags
      displayFinancialStatus displayFulfillmentStatus
      cancelledAt createdAt updatedAt currencyCode
      paymentGatewayNames
      totalPriceSet { shopMoney { amount } }
      totalOutstandingSet { shopMoney { amount } }
      totalDiscountsSet { shopMoney { amount } }
      totalShippingPriceSet { shopMoney { amount } }
      customer { legacyResourceId firstName lastName email phone }
      shippingAddress { firstName lastName address1 address2 city province zip country phone }
      lineItems(first: 60) {
        nodes {
          id title variantTitle quantity sku
          originalUnitPriceSet { shopMoney { amount } }
          discountedUnitPriceSet { shopMoney { amount } }
          product { legacyResourceId }
          variant { legacyResourceId price }
        }
      }
      fulfillments { legacyResourceId status trackingInfo { number company url } }
      refunds { legacyResourceId }
    }
  }
}
GQL;

    /** @param  array<string, mixed>  $node */
    public function toRest(array $node): array
    {
        $id = (int) ($node['legacyResourceId'] ?? 0);
        $financial = strtolower((string) ($node['displayFinancialStatus'] ?? ''));
        $fulfillment = strtolower((string) ($node['displayFulfillmentStatus'] ?? ''));
        $fulfillment = match ($fulfillment) {
            'fulfilled' => 'fulfilled',
            'partially_fulfilled', 'partial' => 'partial',
            'unfulfilled', '' => null,
            default => $fulfillment,
        };

        $customer = $node['customer'] ?? [];
        $address = $node['shippingAddress'] ?? null;

        return [
            'id' => $id,
            'name' => $node['name'] ?? null,
            'order_number' => isset($node['name']) ? ltrim((string) $node['name'], '#') : (string) $id,
            'email' => $node['email'] ?? ($customer['email'] ?? null),
            'phone' => $node['phone'] ?? null,
            'note' => $node['note'] ?? null,
            'tags' => $node['tags'] ?? [],
            'financial_status' => $financial !== '' ? $financial : null,
            'fulfillment_status' => $fulfillment,
            'cancelled_at' => $node['cancelledAt'] ?? null,
            'created_at' => $node['createdAt'] ?? null,
            'updated_at' => $node['updatedAt'] ?? null,
            'currency' => $node['currencyCode'] ?? null,
            'total_price' => data_get($node, 'totalPriceSet.shopMoney.amount', 0),
            'total_outstanding' => data_get($node, 'totalOutstandingSet.shopMoney.amount'),
            'total_discounts' => data_get($node, 'totalDiscountsSet.shopMoney.amount', 0),
            'total_shipping_price_set' => [
                'shop_money' => ['amount' => data_get($node, 'totalShippingPriceSet.shopMoney.amount')],
            ],
            'payment_gateway_names' => $node['paymentGatewayNames'] ?? [],
            'customer' => [
                'id' => isset($customer['legacyResourceId']) ? (int) $customer['legacyResourceId'] : null,
                'first_name' => $customer['firstName'] ?? null,
                'last_name' => $customer['lastName'] ?? null,
                'email' => $customer['email'] ?? null,
                'phone' => $customer['phone'] ?? null,
            ],
            'shipping_address' => is_array($address) ? [
                'first_name' => $address['firstName'] ?? null,
                'last_name' => $address['lastName'] ?? null,
                'address1' => $address['address1'] ?? null,
                'address2' => $address['address2'] ?? null,
                'city' => $address['city'] ?? null,
                'province' => $address['province'] ?? null,
                'zip' => $address['zip'] ?? null,
                'country' => $address['country'] ?? null,
                'phone' => $address['phone'] ?? null,
            ] : null,
            'line_items' => array_map(function (array $line) {
                $gid = (string) ($line['id'] ?? '');
                $numeric = str_contains($gid, '/') ? (int) substr($gid, strrpos($gid, '/') + 1) : (int) $gid;

                return [
                    'id' => $numeric ?: null,
                    'title' => $line['title'] ?? null,
                    'variant_title' => $line['variantTitle'] ?? null,
                    'quantity' => $line['quantity'] ?? 0,
                    'sku' => $line['sku'] ?? null,
                    'price' => data_get($line, 'discountedUnitPriceSet.shopMoney.amount'),
                    'catalog_price' => data_get($line, 'variant.price') ?? data_get($line, 'originalUnitPriceSet.shopMoney.amount'),
                    'product_id' => isset($line['product']['legacyResourceId']) ? (int) $line['product']['legacyResourceId'] : null,
                    'variant_id' => isset($line['variant']['legacyResourceId']) ? (int) $line['variant']['legacyResourceId'] : null,
                ];
            }, $node['lineItems']['nodes'] ?? []),
            'fulfillments' => array_map(function (array $f) {
                $info = $f['trackingInfo'][0] ?? $f['trackingInfo'] ?? [];

                return [
                    'id' => isset($f['legacyResourceId']) ? (int) $f['legacyResourceId'] : null,
                    'status' => isset($f['status']) ? strtolower((string) $f['status']) : null,
                    'tracking_number' => $info['number'] ?? null,
                    'tracking_company' => $info['company'] ?? null,
                    'tracking_url' => $info['url'] ?? null,
                ];
            }, $node['fulfillments'] ?? []),
            'refunds' => array_map(fn ($r) => ['id' => $r['legacyResourceId'] ?? null], $node['refunds'] ?? []),
        ];
    }

    public function client(ShopifyShop $shop): ShopifyClient
    {
        return new ShopifyClient($shop->shop_domain, $shop->access_token, app(ShopifyOAuth::class)->apiVersion());
    }

    /** Loads one order (used by orders/edited, whose payload is not an order). */
    public function fetchRest(ShopifyShop $shop, int|string $orderId): ?array
    {
        $data = $this->client($shop)->graphql(self::ORDER_QUERY, [
            'id' => "gid://shopify/Order/{$orderId}",
        ]);
        $node = $data['order'] ?? null;

        return is_array($node) ? $this->toRest($node) : null;
    }

    public const ORDER_QUERY = <<<'GQL'
query OneOrder($id: ID!) {
  order(id: $id) {
    legacyResourceId name email phone note tags
    displayFinancialStatus displayFulfillmentStatus
    cancelledAt createdAt updatedAt currencyCode
    paymentGatewayNames
    totalPriceSet { shopMoney { amount } }
    totalOutstandingSet { shopMoney { amount } }
    totalDiscountsSet { shopMoney { amount } }
    totalShippingPriceSet { shopMoney { amount } }
    customer { legacyResourceId firstName lastName email phone }
    shippingAddress { firstName lastName address1 address2 city province zip country phone }
    lineItems(first: 60) {
      nodes {
        id title variantTitle quantity sku
        originalUnitPriceSet { shopMoney { amount } }
        discountedUnitPriceSet { shopMoney { amount } }
        product { legacyResourceId }
        variant { legacyResourceId price }
      }
    }
    fulfillments { legacyResourceId status trackingInfo { number company url } }
    refunds { legacyResourceId }
  }
}
GQL;
}
