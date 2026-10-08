<?php

namespace App\Services\Shopify;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderMotifs;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a Shopify order with orderCancel (2026-10).
 * refundMethod replaces the deprecated refund boolean. Default: no refund.
 */
class ShopifyOrderCancelService
{
    private const MUTATION = <<<'GQL'
mutation orderCancel($orderId: ID!, $reason: OrderCancelReason!, $restock: Boolean!, $notifyCustomer: Boolean, $staffNote: String, $refundMethod: OrderCancelRefundMethodInput!) {
  orderCancel(orderId: $orderId, reason: $reason, restock: $restock, notifyCustomer: $notifyCustomer, staffNote: $staffNote, refundMethod: $refundMethod) {
    job { id done }
    orderCancelUserErrors { field message code }
    userErrors { field message }
  }
}
GQL;

    /** @var array<string, string> */
    public const SHOPIFY_REASONS = [
        'client' => 'CUSTOMER',
        'doublon' => 'OTHER',
        'saisie' => 'STAFF',
        'stock' => 'INVENTORY',
        'autre' => 'OTHER',
    ];

    public function __construct(
        private readonly ShopifyOrderNormalizer $normalizer,
        private readonly ShopifySyncLogger $logger,
    ) {}

    /**
     * @return array{pending: bool}
     */
    public function cancel(Order $order, User $user, string $reasonCode, ?string $comment, bool $refund): array
    {
        $shop = $order->shop ?: $order->shop()->first();
        if (! $shop || ! ($shop->capabilities()['orders_cancel'] ?? false)) {
            throw ValidationException::withMessages([
                'shopify' => 'Annulation Shopify non autorisée — reconnecter Shopify',
            ]);
        }

        $staff = trim(OrderMotifs::label($reasonCode).($comment ? ' — '.$comment : ''));
        $variables = [
            'orderId' => 'gid://shopify/Order/'.$order->shopify_order_id,
            'reason' => self::SHOPIFY_REASONS[$reasonCode] ?? 'OTHER',
            'restock' => true,
            'notifyCustomer' => false,
            'staffNote' => mb_substr($staff, 0, 255),
            'refundMethod' => ['originalPaymentMethodsRefund' => $refund],
        ];

        try {
            $data = $this->normalizer->client($shop)->graphql(self::MUTATION, $variables);
        } catch (ShopifyApiException $e) {
            $this->fail($order, $shop->id, $user, $e->getMessage(), $variables);
            throw ValidationException::withMessages(['shopify' => 'Shopify a refusé l’annulation : '.$e->getMessage()]);
        }

        $payload = $data['orderCancel'] ?? [];
        $errors = array_merge($payload['orderCancelUserErrors'] ?? [], $payload['userErrors'] ?? []);
        if ($errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->implode(' ');
            $this->fail($order, $shop->id, $user, $message !== '' ? $message : 'Shopify a refusé l’annulation.', $variables);
            throw ValidationException::withMessages([
                'shopify' => 'Shopify a refusé l’annulation : '.($message !== '' ? $message : 'erreur inconnue'),
            ]);
        }

        $done = (bool) ($payload['job']['done'] ?? false);
        $this->logger->log([
            'company_id' => $order->company_id,
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'shopify_id' => (string) $order->shopify_order_id,
            'action' => 'orderCancel',
            'source' => 'flow_user',
            'user_id' => $user->id,
            'status' => 'success',
            'request_excerpt' => $variables,
            'response_excerpt' => ['job' => $payload['job'] ?? null],
        ]);

        return ['pending' => ! $done];
    }

    /** @param  array<string, mixed>  $variables */
    private function fail(Order $order, int $shopId, User $user, string $message, array $variables): void
    {
        $this->logger->log([
            'company_id' => $order->company_id,
            'shopify_shop_id' => $shopId,
            'direction' => 'out',
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'shopify_id' => (string) $order->shopify_order_id,
            'action' => 'orderCancel',
            'source' => 'flow_user',
            'user_id' => $user->id,
            'status' => 'failed',
            'error' => $message.' — Réessayer',
            'request_excerpt' => $variables,
        ]);
    }
}
