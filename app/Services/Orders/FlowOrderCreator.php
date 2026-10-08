<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\ConfirmationStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\ShopifyShop;
use App\Models\User;
use App\Services\Automations\AutomationDispatcher;
use App\Services\Clients\ClientService;
use App\Services\Shopify\OrderSyncService;
use App\Services\Shopify\ShopifyApiException;
use App\Services\Shopify\ShopifyOrderNormalizer;
use App\Services\Shopify\ShopifySyncLogger;
use App\Support\Catalog;
use App\Support\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates one Flow order and, when the shop can, the Shopify order (orderCreate).
 * creation_key makes a double-click return the same row.
 */
class FlowOrderCreator
{
    public const SOURCE = 'Lav’Fast Flow';

    public const IN_PROGRESS = 'Création déjà en cours…';

    public const NO_SHOP = 'Boutique Shopify non connectée : commande créée uniquement dans Lav’Fast Flow';

    public const RATE_LIMIT = 'La boutique Shopify limite la création de commandes (quelques commandes par minute). Réessayez dans un instant.';

    public const PHONE_WARNING = 'Le téléphone n’a pas été accepté par Shopify : la commande est créée sans fiche client, le téléphone reste sur l’adresse de livraison.';

    public const AMBIGUOUS = 'Shopify n’a pas répondu : la commande a peut-être été créée. Réessayer vérifie d’abord Shopify.';

    private const MUTATION = <<<'GQL'
mutation orderCreate($order: OrderCreateOrderInput!, $options: OrderCreateOptionsInput) {
  orderCreate(order: $order, options: $options) {
    userErrors { field message }
    order {
      legacyResourceId name email phone note tags sourceIdentifier
      customAttributes { key value }
      displayFinancialStatus displayFulfillmentStatus
      cancelledAt createdAt updatedAt currencyCode paymentGatewayNames
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

    private const SEARCH = <<<'GQL'
query FindFlowOrder($query: String!, $first: Int!) {
  orders(first: $first, sortKey: CREATED_AT, reverse: true, query: $query) {
    nodes {
      legacyResourceId name email phone note tags sourceIdentifier
      customAttributes { key value }
      displayFinancialStatus displayFulfillmentStatus
      cancelledAt createdAt updatedAt currencyCode paymentGatewayNames
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
    }
  }
}
GQL;

    public function __construct(
        private readonly ShopifyOrderNormalizer $normalizer,
        private readonly OrderSyncService $sync,
        private readonly ShopifySyncLogger $logger,
        private readonly AutomationDispatcher $automations,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{order: Order, status: int, warnings: list<string>, notice: ?string}
     */
    public function create(User $user, array $input): array
    {
        abort_unless($user->can('orders.create'), 403);

        $built = $this->prepare($user, $input);
        $claimed = $this->claim($user, $input, $built);
        $order = $claimed['order'];

        if ($claimed['replay']) {
            return ['order' => $order->fresh(), 'status' => 200, 'warnings' => [], 'notice' => $order->flow_notice];
        }
        if ($built['draft']) {
            return ['order' => $order->fresh(), 'status' => $claimed['existed'] ? 200 : 201, 'warnings' => $built['warnings'], 'notice' => null];
        }

        $shop = $this->shopFor($user);
        try {
            if (! $shop || ! ($shop->capabilities()['orders_create'] ?? false)) {
                $finished = $this->finishLocal($order, $user, self::NO_SHOP);

                return ['order' => $finished, 'status' => 201, 'warnings' => $built['warnings'], 'notice' => self::NO_SHOP];
            }

            $pushed = $this->push($shop, $order, $built, $user, (bool) $claimed['was_failed']);

            return ['order' => $pushed['order'], 'status' => 201, 'warnings' => array_values(array_filter([...$built['warnings'], $pushed['warning']])), 'notice' => null];
        } catch (ShopifyApiException $e) {
            if ($kept = $this->keepAttached($order)) {
                return $kept;
            }
            $message = $this->french($e);
            $this->markFailed($order, $shop, $user, $message);
            throw ValidationException::withMessages(['shopify' => $message]);
        } catch (\Throwable $e) {
            if ($kept = $this->keepAttached($order)) {
                return $kept;
            }
            $message = $e instanceof ValidationException
                ? (string) (collect($e->errors())->flatten()->first() ?: 'La création a échoué.')
                : 'La création a échoué. Réessayez.';
            $this->markFailed($order, $shop, $user, $message);
            throw $e;
        }
    }

    /** Réessayer a failed draft with the same creation_key. */
    public function retry(User $user, Order $order): array
    {
        abort_unless($user->can('orders.create'), 403);
        abort_unless($order->creation_key && $order->shopify_sync_status === 'failed' && ! $order->shopify_order_id, 422, 'Aucun échec à relancer.');

        return $this->create($user, $this->inputFromOrder($order));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{draft: bool, warnings: list<string>, lines: list<array<string, mixed>>, fees: list<array{label: string, amount: float}>, totals: array<string, float>, financial: string, amount_paid: float, gateway: string, phone_e164: ?string, currency: string}
     */
    private function prepare(User $user, array $input): array
    {
        $companyId = $user->resolveCompanyId();
        $willPush = ! (bool) ($input['draft'] ?? false);
        $shop = $willPush ? $this->shopFor($user) : null;
        $willPush = $willPush && $shop && ($shop->capabilities()['orders_create'] ?? false);
        $variants = ProductVariant::query()
            ->with('product')
            ->where('company_id', $companyId)
            ->whereIn('id', collect($input['lines'])->pluck('variant_id'))
            ->get()
            ->keyBy('id');

        $warnings = [];
        $lines = [];
        foreach ($input['lines'] as $index => $row) {
            $variant = $variants->get((int) $row['variant_id']);
            if (! $variant || ! $variant->product) {
                throw ValidationException::withMessages(["lines.$index" => 'Produit introuvable dans le catalogue.']);
            }
            $qty = max(1, (int) $row['quantity']);
            $catalog = round((float) $variant->price, 2);
            $sold = array_key_exists('price', $row) && $row['price'] !== null && $row['price'] !== ''
                ? round((float) $row['price'], 2)
                : $catalog;
            if (abs($sold - $catalog) >= 0.009 && ! $user->can('orders.edit_prices')) {
                abort(403, 'Vous n’avez pas le droit de modifier le prix d’un produit.');
            }
            if ($willPush && ! $variant->shopify_variant_id) {
                throw ValidationException::withMessages(["lines.$index" => 'Ce produit n’a pas d’identifiant Shopify.']);
            }
            if ($variant->inventory_tracked && $qty > (int) $variant->inventory_quantity && ! $variant->allowsOversell()) {
                throw ValidationException::withMessages(["lines.$index" => 'Stock insuffisant']);
            }
            if ($variant->isOutOfStock() || ($variant->inventory_tracked && $qty > (int) $variant->inventory_quantity)) {
                $warnings[] = 'Stock insuffisant';
            }
            $lines[] = [
                'title' => $variant->product->title,
                'variant_title' => $variant->displayTitle(),
                'sku' => $variant->sku,
                'quantity' => $qty,
                'price' => $sold,
                'catalog_price' => $catalog,
                'variant_id' => $variant->shopify_variant_id ? (int) $variant->shopify_variant_id : null,
                'local_variant_id' => $variant->id,
                'product_id' => $variant->product->shopify_product_id ? (int) $variant->product->shopify_product_id : null,
                'image' => $variant->imageUrl(),
                'price_overridden' => abs($sold - $catalog) >= 0.009,
            ];
        }

        $subtotal = round(collect($lines)->sum(fn ($l) => $l['price'] * $l['quantity']), 2);
        $kind = $input['discount_kind'] ?? null;
        $discountValue = round((float) ($input['discount_value'] ?? 0), 2);
        if ($discountValue > 0 && ! $user->can('orders.discount')) {
            abort(403, 'Vous n’avez pas le droit d’appliquer une remise.');
        }
        $discount = 0.0;
        if ($discountValue > 0 && $kind === 'percent') {
            $discount = round($subtotal * min($discountValue, 100) / 100, 2);
        } elseif ($discountValue > 0) {
            $kind = 'amount';
            $discount = round(min($discountValue, $subtotal), 2);
        }
        $fees = [];
        foreach ($input['fees'] ?? [] as $fee) {
            $amount = round((float) ($fee['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $fees[] = ['label' => trim((string) ($fee['label'] ?? 'Frais')), 'amount' => $amount];
        }
        $feesTotal = round(collect($fees)->sum('amount'), 2);
        $shipping = round(max(0, (float) ($input['shipping_price'] ?? 0)), 2);
        $total = round(max(0, $subtotal - $discount + $shipping + $feesTotal), 2);

        $method = (string) ($input['payment_method'] ?? 'cod');
        $paid = round(max(0, (float) ($input['amount_paid'] ?? 0)), 2);
        if ($method === 'paye' && $paid <= 0) {
            $paid = $total;
        }
        if ($method === 'cod') {
            $paid = 0.0;
        }
        $paid = round(min($paid, $total), 2);
        $financial = $paid <= 0 ? 'pending' : ($paid + 0.009 >= $total ? 'paid' : 'partially_paid');
        $gateway = trim((string) ($input['payment_label'] ?? ''));
        if ($gateway === '') {
            $gateway = $financial === 'pending' ? 'Cash on Delivery' : (Catalog::PAYMENT_METHODS[$method] ?? 'Paiement');
        }
        if ($financial === 'pending') {
            $gateway = 'Cash on Delivery';
        }

        $commercialId = (int) ($input['commercial_user_id'] ?? $user->id);
        $commercial = User::query()->find($commercialId);
        if (! $commercial || (int) $commercial->company_id !== $companyId || $commercial->is_active === false || $commercial->isLivreur() || ! Permissions::allows($commercial, 'orders.create')) {
            throw ValidationException::withMessages(['commercial_user_id' => 'Ce commercial ne peut pas porter la commande.']);
        }
        if (! empty($input['assigned_user_id'])) {
            $assigned = User::query()->find((int) $input['assigned_user_id']);
            if (! $assigned || (int) $assigned->company_id !== $companyId || $assigned->isLivreur()) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Cet agent n’appartient pas à cette société.']);
            }
        }

        $phone = ClientService::key($input['customer_phone'] ?? null);
        if (! $phone) {
            throw ValidationException::withMessages(['customer_phone' => 'Téléphone invalide.']);
        }

        return [
            'draft' => (bool) ($input['draft'] ?? false),
            'warnings' => array_values(array_unique($warnings)),
            'lines' => $lines,
            'fees' => $fees,
            'totals' => [
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping' => $shipping,
                'fees' => $feesTotal,
                'total' => $total,
                'due' => round(max(0, $total - $paid), 2),
            ],
            'financial' => $financial,
            'amount_paid' => $paid,
            'gateway' => $gateway,
            'phone_e164' => '+'.$phone,
            'currency' => 'MAD',
            'commercial_id' => $commercial->id,
            'discount_kind' => $discount > 0 ? ($kind === 'percent' ? 'percent' : 'amount') : null,
            'discount_value' => $discount > 0 ? $discountValue : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $built
     * @return array{order: Order, replay: bool, existed: bool, was_failed: bool}
     */
    private function claim(User $user, array $input, array $built): array
    {
        $key = (string) $input['creation_key'];
        $companyId = $user->resolveCompanyId();

        try {
            return DB::transaction(function () use ($user, $input, $built, $key, $companyId) {
                $existing = Order::query()->where('creation_key', $key)->lockForUpdate()->first();
                if ($existing && (int) $existing->company_id !== $companyId) {
                    throw ValidationException::withMessages(['creation_key' => 'Clé de création invalide.']);
                }
                if ($replay = $this->replay($existing)) {
                    return $replay;
                }
                if ($existing && $existing->flow_state === 'creating' && ! $this->claimIsStale($existing)) {
                    abort(409, self::IN_PROGRESS);
                }

                $wasFailed = (bool) ($existing && (
                    $existing->shopify_sync_status === 'failed'
                    || ($existing->flow_state === 'creating' && $this->claimIsStale($existing))
                ));
                $order = $existing ?? new Order;
                $existed = $existing !== null;
                $this->fill($order, $user, $input, $built, $key);
                $order->flow_state = $built['draft'] ? 'draft' : 'creating';
                $order->shopify_sync_status = $built['draft'] ? $order->shopify_sync_status : 'pending';
                if (! $built['draft']) {
                    $order->shopify_sync_error = null;
                }
                $order->save();

                return ['order' => $order, 'replay' => false, 'existed' => $existed, 'was_failed' => $wasFailed];
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            if ($e instanceof QueryException && ! str_contains($e->getMessage(), 'creation_key')) {
                throw $e;
            }
            $existing = Order::query()->where('creation_key', $key)->first();
            if ($existing && (int) $existing->company_id !== $companyId) {
                throw ValidationException::withMessages(['creation_key' => 'Clé de création invalide.']);
            }
            if ($replay = $this->replay($existing)) {
                return $replay;
            }
            if ($existing && $existing->flow_state === 'creating' && ! $this->claimIsStale($existing)) {
                abort(409, self::IN_PROGRESS);
            }
            if ($existing && $this->claimIsStale($existing)) {
                return ['order' => $existing, 'replay' => false, 'existed' => true, 'was_failed' => true];
            }
            throw ValidationException::withMessages(['creation_key' => self::IN_PROGRESS]);
        }
    }

    /** @return array{order: Order, replay: bool, existed: bool, was_failed: bool}|null */
    private function replay(?Order $existing): ?array
    {
        if ($existing && ($existing->shopify_order_id || $existing->flow_state === 'created')) {
            return ['order' => $existing, 'replay' => true, 'existed' => true, 'was_failed' => false];
        }

        return null;
    }

    private function claimIsStale(Order $order): bool
    {
        return $order->updated_at !== null && $order->updated_at->lte(now()->subMinutes(2));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $built
     */
    private function fill(Order $order, User $user, array $input, array $built, string $key): void
    {
        $name = trim((string) $input['customer_name']);
        $order->fill([
            'company_id' => $user->resolveCompanyId(),
            'creation_key' => $key,
            'source' => self::SOURCE,
            'created_by' => $order->created_by ?: $user->id,
            'commercial_user_id' => $built['commercial_id'],
            'assigned_user_id' => $input['assigned_user_id'] ?? $order->assigned_user_id,
            'customer_name' => $name,
            'phone' => $input['customer_phone'],
            'email' => $input['email'] ?? null,
            'note' => $input['note'] ?? null,
            'internal_note' => $input['internal_note'] ?? null,
            'currency' => $built['currency'],
            'line_items' => $this->localLines($built),
            'extra_fees' => $built['fees'],
            'discount_total' => $built['totals']['discount'],
            'discount_kind' => $built['discount_kind'],
            'discount_value' => $built['discount_value'],
            'shipping_price' => $built['totals']['shipping'],
            'total_price' => $built['totals']['total'],
            'amount_paid' => $built['amount_paid'],
            'financial_status' => $built['financial'],
            'total_outstanding' => $built['totals']['due'],
            'payment_gateway_names' => [$built['gateway']],
            'shipping_address' => [
                'address1' => $input['address'] ?? null,
                'city' => $input['city'] ?? null,
                'country' => 'Morocco',
                'country_code' => 'MA',
                'phone' => $built['phone_e164'],
                'name' => $name,
            ],
            'shopify_customer_id' => $input['shopify_customer_id'] ?? $order->shopify_customer_id,
            'confirmation_status' => $order->confirmation_status ?: ConfirmationStatus::defaultCode(),
            'status' => $order->status ?: 'pending',
        ]);
    }

    /** @param  array<string, mixed>  $built */
    private function localLines(array $built): array
    {
        $lines = $built['lines'];
        foreach ($built['fees'] as $fee) {
            $lines[] = [
                'title' => $fee['label'],
                'quantity' => 1,
                'price' => $fee['amount'],
                'catalog_price' => $fee['amount'],
                'fee' => true,
                'requires_shipping' => false,
            ];
        }

        return $lines;
    }

    private function finishLocal(Order $order, User $user, ?string $notice): Order
    {
        $order->forceFill([
            'flow_state' => 'created',
            'flow_notice' => $notice,
            'shopify_sync_status' => null,
            'shopify_sync_error' => null,
        ])->save();
        $this->rememberCreated($order->fresh(), $user, null);
        $this->automations->orderCreated($order->fresh());

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $built
     * @return array{order: Order, warning: ?string}
     */
    private function push(ShopifyShop $shop, Order $order, array $built, User $user, bool $searchFirst): array
    {
        $warning = null;
        $key = (string) $order->creation_key;
        if ($searchFirst && ($found = $this->searchNode($shop, $key))) {
            $node = $found;
        } else {
            $variables = $this->variables($order, $built, $shop, true);
            try {
                $node = $this->mutate($shop, $variables, true, $key, $warning);
            } catch (ShopifyApiException $e) {
                $found = $this->ambiguous($e) ? $this->searchNode($shop, $key) : null;
                if (! $found) {
                    throw $e;
                }
                $node = $found;
            }
        }

        $rest = $this->normalizer->toRest($node);
        $rest['note_attributes'] = [['name' => 'lavfast_creation_key', 'value' => $order->creation_key]];
        $rest['source_identifier'] = $order->creation_key;
        if (($rest['line_items'] ?? []) === []) {
            $rest['line_items'] = $order->line_items ?? [];
        }
        $local = $this->sync->upsertFromShopifyPayload($shop, $rest, 'flow_user');
        $local->forceFill([
            'source' => self::SOURCE,
            'created_by' => $order->created_by,
            'commercial_user_id' => $order->commercial_user_id,
            'internal_note' => $order->internal_note,
            'creation_key' => $order->creation_key,
            'flow_state' => 'created',
            'flow_notice' => $warning,
            'extra_fees' => $built['fees'],
            'discount_kind' => $built['discount_kind'],
            'discount_value' => $built['discount_value'],
            'shopify_sync_status' => 'synced',
            'shopify_sync_error' => null,
            'confirmation_status' => $order->confirmation_status,
        ])->save();
        $this->restoreCatalogPrices($local, $built['lines']);
        $this->rememberCreated($local->fresh(), $user, $local->name);
        $this->automations->orderCreated($local->fresh());
        $this->logger->log([
            'company_id' => $local->company_id,
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'order',
            'entity_id' => $local->id,
            'shopify_id' => (string) $local->shopify_order_id,
            'action' => 'orderCreate',
            'source' => 'flow_user',
            'user_id' => $user->id,
            'status' => 'success',
            'request_excerpt' => ['creation_key' => $order->creation_key],
        ]);

        return ['order' => $local->fresh(), 'warning' => $warning];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function mutate(ShopifyShop $shop, array $variables, bool $allowPhoneRetry, string $key, ?string &$warning): array
    {
        $client = $this->normalizer->client($shop);
        $attempt = 0;
        $phoneRetried = false;
        while (true) {
            $attempt++;
            try {
                $data = $client->graphqlMutation(self::MUTATION, $variables, 'orderCreate');

                return $data['orderCreate']['order'] ?? [];
            } catch (ShopifyApiException $e) {
                if (! $phoneRetried && $allowPhoneRetry && $this->phoneRejected($e)) {
                    $phoneRetried = true;
                    $warning = self::PHONE_WARNING;
                    unset($variables['order']['customer']);
                    $attempt--;

                    continue;
                }
                if ($this->ambiguous($e)) {
                    $found = $this->searchNode($shop, $key);
                    if ($found) {
                        return $found;
                    }
                    throw $e;
                }
                if ($e->retryable && $attempt < 3) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function searchNode(ShopifyShop $shop, string $key): ?array
    {
        $match = $this->matchCreationKey($this->searchOrders($shop, 'tag:"'.$key.'"', 5), $key);
        if ($match) {
            return $match;
        }

        return $this->matchCreationKey($this->searchOrders($shop, 'tag:lavfast-flow', 50), $key);
    }

    /** @return list<mixed> */
    private function searchOrders(ShopifyShop $shop, string $query, int $first): array
    {
        try {
            $data = $this->normalizer->client($shop)->graphql(self::SEARCH, [
                'query' => $query,
                'first' => $first,
            ]);
        } catch (ShopifyApiException) {
            return [];
        }

        return is_array($data['orders']['nodes'] ?? null) ? $data['orders']['nodes'] : [];
    }

    /**
     * @param  list<mixed>  $nodes
     * @return array<string, mixed>|null
     */
    private function matchCreationKey(array $nodes, string $key): ?array
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $rest = $this->normalizer->toRest($node);
            if (OrderSyncService::creationKeyFromPayload($rest) === $key || ($node['sourceIdentifier'] ?? null) === $key) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $built
     * @return array{order: array<string, mixed>, options: array<string, mixed>}
     */
    private function variables(Order $order, array $built, ShopifyShop $shop, bool $linkCustomer): array
    {
        $currency = $shop->currency ?: $built['currency'];
        $money = fn (float $amount) => ['shopMoney' => ['amount' => number_format($amount, 2, '.', ''), 'currencyCode' => $currency]];
        $parts = preg_split('/\s+/', trim((string) $order->customer_name), 2) ?: [];
        $first = $parts[0] ?: 'Client';
        $last = $parts[1] ?? 'Client';

        $lineItems = [];
        foreach ($built['lines'] as $line) {
            $item = [
                'variantId' => 'gid://shopify/ProductVariant/'.$line['variant_id'],
                'quantity' => $line['quantity'],
            ];
            if ($line['price_overridden']) {
                $item['priceSet'] = $money($line['price']);
            }
            $lineItems[] = $item;
        }
        foreach ($built['fees'] as $fee) {
            $lineItems[] = [
                'title' => $fee['label'],
                'quantity' => 1,
                'priceSet' => $money($fee['amount']),
                'requiresShipping' => false,
                'taxable' => false,
            ];
        }

        $total = $built['totals']['total'];
        $paid = $built['amount_paid'];
        $due = $built['totals']['due'];
        $transactions = [];
        if ($built['financial'] === 'pending') {
            $transactions[] = ['kind' => 'SALE', 'status' => 'PENDING', 'gateway' => 'Cash on Delivery', 'amountSet' => $money($total)];
            $financial = 'PENDING';
        } elseif ($built['financial'] === 'paid') {
            $transactions[] = ['kind' => 'SALE', 'status' => 'SUCCESS', 'gateway' => $built['gateway'], 'amountSet' => $money($paid)];
            $financial = 'PAID';
        } else {
            $transactions[] = ['kind' => 'SALE', 'status' => 'SUCCESS', 'gateway' => $built['gateway'], 'amountSet' => $money($paid)];
            if ($due > 0) {
                $transactions[] = ['kind' => 'SALE', 'status' => 'PENDING', 'gateway' => 'Cash on Delivery', 'amountSet' => $money($due)];
            }
            $financial = 'PARTIALLY_PAID';
        }

        $payload = [
            'lineItems' => $lineItems,
            'currency' => $currency,
            'financialStatus' => $financial,
            'transactions' => $transactions,
            'note' => $order->note,
            'tags' => ['lavfast-flow', (string) $order->creation_key],
            'sourceIdentifier' => (string) $order->creation_key,
            'sourceName' => 'lavfast-flow',
            'customAttributes' => [['key' => 'lavfast_creation_key', 'value' => (string) $order->creation_key]],
            'shippingAddress' => [
                'firstName' => $first,
                'lastName' => $last,
                'address1' => $order->shipping_address['address1'] ?? '',
                'city' => $order->shipping_address['city'] ?? '',
                'countryCode' => 'MA',
                'phone' => $built['phone_e164'],
            ],
        ];
        if ($built['totals']['shipping'] > 0) {
            $payload['shippingLines'] = [[
                'title' => 'Livraison',
                'priceSet' => $money($built['totals']['shipping']),
            ]];
        }
        if ($built['totals']['discount'] > 0) {
            $payload['discountCode'] = ['itemFixedDiscountCode' => [
                'code' => 'LAVFAST',
                'amountSet' => $money($built['totals']['discount']),
            ]];
        }
        if ($linkCustomer) {
            if ($order->shopify_customer_id) {
                $payload['customer'] = ['toAssociate' => ['id' => 'gid://shopify/Customer/'.$order->shopify_customer_id]];
            } else {
                $customer = ['firstName' => $first, 'lastName' => $last, 'phone' => $built['phone_e164']];
                if ($order->email) {
                    $customer['email'] = $order->email;
                }
                $payload['customer'] = ['toUpsert' => $customer];
            }
        }

        $company = Company::query()->find($order->company_id);
        $behaviour = (string) ($company?->features['shopify_inventory_behaviour'] ?? 'DECREMENT_OBEYING_POLICY');
        if (! in_array($behaviour, ['BYPASS', 'DECREMENT_IGNORING_POLICY', 'DECREMENT_OBEYING_POLICY'], true)) {
            $behaviour = 'DECREMENT_OBEYING_POLICY';
        }

        return [
            'order' => $payload,
            'options' => [
                'sendReceipt' => false,
                'sendFulfillmentReceipt' => false,
                'inventoryBehaviour' => $behaviour,
            ],
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function restoreCatalogPrices(Order $order, array $lines): void
    {
        $byVariant = collect($lines)->keyBy('variant_id');
        $items = $order->line_items ?? [];
        foreach ($items as &$item) {
            $match = $byVariant->get((int) ($item['variant_id'] ?? 0));
            if ($match) {
                $item['catalog_price'] = $match['catalog_price'];
                $item['price'] = $match['price'];
                $item['local_variant_id'] = $match['local_variant_id'];
            }
        }
        unset($item);
        $order->forceFill(['line_items' => $items])->save();
    }

    private function rememberCreated(Order $order, User $user, ?string $shopifyName): void
    {
        $exists = OrderStatusHistory::query()->where('order_id', $order->id)->where('status_code', 'created_in_flow')->exists();
        if ($exists) {
            return;
        }
        $label = 'Commande créée dans Lav’Fast Flow par '.$user->name.($shopifyName ? ' — Shopify '.$shopifyName : '');
        $order->appendHistory('created_in_flow', $label, $user, [
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_name' => $shopifyName,
            'commercial_user_id' => $order->commercial_user_id,
        ]);
        $order->save();
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'kind' => 'commande',
            'status_code' => 'created_in_flow',
            'status_name' => 'Commande créée',
            'status_color' => '#2563eb',
            'note' => $label,
            'data' => [
                'user' => $user->name,
                'commercial_user_id' => $order->commercial_user_id,
                'source' => self::SOURCE,
                'shopify_order_id' => $order->shopify_order_id,
                'shopify_name' => $shopifyName,
            ],
            'user_id' => $user->id,
        ]);
    }

    private function shopFor(User $user): ?ShopifyShop
    {
        return ShopifyShop::query()
            ->where('company_id', $user->resolveCompanyId())
            ->where('is_active', true)
            ->whereNull('uninstalled_at')
            ->whereNotNull('access_token')
            ->first();
    }

    /** @return array<string, mixed> */
    private function inputFromOrder(Order $order): array
    {
        $lines = [];
        foreach ($order->line_items ?? [] as $line) {
            if (! empty($line['fee']) || empty($line['local_variant_id'])) {
                continue;
            }
            $lines[] = [
                'variant_id' => $line['local_variant_id'],
                'quantity' => $line['quantity'] ?? 1,
                'price' => $line['price'] ?? null,
            ];
        }

        return [
            'creation_key' => $order->creation_key,
            'draft' => false,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->phone,
            'email' => $order->email,
            'city' => $order->shipping_address['city'] ?? null,
            'address' => $order->shipping_address['address1'] ?? null,
            'lines' => $lines,
            'fees' => $order->extra_fees ?? [],
            'discount_kind' => $order->discount_kind,
            'discount_value' => $order->discount_value,
            'shipping_price' => $order->shipping_price,
            'payment_method' => $order->financial_status === 'paid' ? 'paye' : ($order->financial_status === 'partially_paid' ? 'paye' : 'cod'),
            'amount_paid' => $order->amount_paid,
            'payment_label' => ($order->payment_gateway_names[0] ?? null) !== 'Cash on Delivery' ? ($order->payment_gateway_names[0] ?? null) : null,
            'note' => $order->note,
            'internal_note' => $order->internal_note,
            'commercial_user_id' => $order->commercial_user_id,
            'assigned_user_id' => $order->assigned_user_id,
            'shopify_customer_id' => $order->shopify_customer_id,
        ];
    }

    private function phoneRejected(ShopifyApiException $e): bool
    {
        foreach ($e->userErrors as $error) {
            $field = strtolower(implode('.', (array) ($error['field'] ?? [])));
            $message = strtolower((string) ($error['message'] ?? ''));
            if (str_contains($field, 'phone') || str_contains($message, 'phone')) {
                return true;
            }
        }

        return str_contains(strtolower($e->getMessage()), 'phone');
    }

    private function ambiguous(ShopifyApiException $e): bool
    {
        if ($e->status === null || $e->status >= 500) {
            return true;
        }
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timeout') || str_contains($message, 'injoignable');
    }

    private function french(ShopifyApiException $e): string
    {
        if ($this->ambiguous($e)) {
            return self::AMBIGUOUS;
        }
        if ($e->status === 429 || str_contains(strtoupper($e->getMessage()), 'THROTTLED')) {
            return self::RATE_LIMIT;
        }

        return 'Shopify a refusé la commande : '.$e->getMessage();
    }

    /** @return array{order: Order, status: int, warnings: list<string>, notice: ?string}|null */
    private function keepAttached(Order $order): ?array
    {
        $fresh = $order->fresh();
        if (! $fresh?->shopify_order_id) {
            return null;
        }
        $fresh->forceFill([
            'flow_state' => 'created',
            'shopify_sync_status' => 'synced',
            'shopify_sync_error' => null,
        ])->save();

        return ['order' => $fresh->fresh(), 'status' => 200, 'warnings' => [], 'notice' => $fresh->flow_notice];
    }

    private function markFailed(Order $order, ?ShopifyShop $shop, User $user, string $message): void
    {
        $order->forceFill([
            'flow_state' => 'draft',
            'shopify_sync_status' => 'failed',
            'shopify_sync_error' => $message,
        ])->save();
        if (! $shop) {
            return;
        }
        $this->logger->log([
            'company_id' => $order->company_id,
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'orderCreate',
            'source' => 'flow_user',
            'user_id' => $user->id,
            'status' => 'failed',
            'error' => $message,
            'request_excerpt' => ['creation_key' => $order->creation_key],
        ]);
    }
}
