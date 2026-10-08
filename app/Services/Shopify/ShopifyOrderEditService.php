<?php

namespace App\Services\Shopify;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\ShopifyShop;
use App\Models\User;
use App\Services\Catalog\OrderLines;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pushes order line and customer changes to Shopify, then updates Lav'Fast Flow
 * from the committed order. A rejected edit leaves the local order unchanged.
 *
 * A price increase on an existing line is not supported by the order-edit API.
 * The user must replace the product (or apply a discount). Decreases become a
 * line discount.
 */
class ShopifyOrderEditService
{
    public const UNAUTHORIZED = 'Modification Shopify non autorisée — reconnecter Shopify';

    public const PRICE_INCREASE = 'Shopify n’autorise pas d’augmenter le prix d’une ligne existante. Utilisez « Remplacer produit » ou appliquez une remise.';

    public const LEGACY_CONFLICT = 'Cette commande contient des modifications locales jamais envoyées à Shopify (conflit). Choisissez d’abord « Reprendre la version Shopify ».';

    public const DISCOUNT_MISSING = 'Cette remise est introuvable sur Shopify.';

    public function __construct(
        protected ShopifyOrderNormalizer $normalizer,
        protected OrderSyncService $sync,
        protected ShopifySyncLogger $logger,
    ) {}

    public function isConnectedOrder(Order $order): bool
    {
        return (bool) $order->shopify_order_id && $order->shopify_shop_id && $order->shop;
    }

    /**
     * @return array{order: Order, warning: ?string, changed: bool}
     */
    public function add(Order $order, ProductVariant $variant, int $quantity, ?float $price, User $user): array
    {
        $this->refuseLegacy($order);
        $shop = $this->shop($order);
        $this->requireVariant($variant);

        return $this->edit($shop, $order, $user, 'add', [
            'op' => 'add', 'order_id' => $order->id, 'variant_id' => $variant->id, 'quantity' => $quantity, 'price' => $price,
        ], function (ShopifyClient $client, string $calcId, array $nodes) use ($variant, $quantity) {
            $this->mutate($client, self::ADD_VARIANT, [
                'id' => $calcId,
                'variantId' => $this->gid('ProductVariant', $variant->shopify_variant_id),
                'quantity' => $quantity,
            ], 'orderEditAddVariant');
        }, 'Produit ajouté');
    }

    /**
     * @return array{order: Order, warning: ?string, changed: bool}
     */
    public function updateQuantity(Order $order, string $key, int $quantity, User $user): array
    {
        $this->refuseLegacy($order);
        $shop = $this->shop($order);
        $line = $this->line($order, $key);

        return $this->edit($shop, $order, $user, 'quantity', [
            'op' => 'quantity', 'order_id' => $order->id, 'key' => $key, 'quantity' => $quantity,
        ], function (ShopifyClient $client, string $calcId, array $nodes) use ($line, $quantity) {
            $this->mutate($client, self::SET_QTY, [
                'id' => $calcId,
                'lineItemId' => $this->calculatedLineId($nodes, $line),
                'quantity' => $quantity,
            ], 'orderEditSetQuantity');
        }, 'Quantité modifiée');
    }

    /**
     * @return array{order: Order, warning: ?string, changed: bool}
     */
    public function updatePrice(Order $order, string $key, float $price, User $user): array
    {
        $this->refuseLegacy($order);
        $shop = $this->shop($order);
        $line = $this->line($order, $key);
        $old = (float) ($line['price'] ?? 0);
        if ($price > $old + 0.009) {
            throw ValidationException::withMessages(['price' => self::PRICE_INCREASE]);
        }
        $discount = round(($old - $price) * (int) ($line['quantity'] ?? 1), 2);

        return $this->edit($shop, $order, $user, 'price', [
            'op' => 'price', 'order_id' => $order->id, 'key' => $key, 'price' => $price,
        ], function (ShopifyClient $client, string $calcId, array $nodes) use ($line, $discount, $order) {
            $this->mutate($client, self::ADD_DISCOUNT, [
                'id' => $calcId,
                'lineItemId' => $this->calculatedLineId($nodes, $line),
                'discount' => [
                    'description' => 'Remise Lav’Fast Flow',
                    'fixedValue' => [
                        'amount' => number_format($discount, 2, '.', ''),
                        'currencyCode' => $order->currency ?: 'MAD',
                    ],
                ],
            ], 'orderEditAddLineItemDiscount');
        }, 'Prix modifié');
    }

    /**
     * @return array{order: Order, warning: ?string, changed: bool}
     */
    public function remove(Order $order, string $key, User $user): array
    {
        $this->refuseLegacy($order);
        $shop = $this->shop($order);
        $line = $this->line($order, $key);

        return $this->edit($shop, $order, $user, 'remove', [
            'op' => 'remove', 'order_id' => $order->id, 'key' => $key,
        ], function (ShopifyClient $client, string $calcId, array $nodes) use ($line) {
            $this->mutate($client, self::SET_QTY, [
                'id' => $calcId,
                'lineItemId' => $this->calculatedLineId($nodes, $line),
                'quantity' => 0,
            ], 'orderEditSetQuantity');
        }, 'Produit supprimé');
    }

    /**
     * @return array{order: Order, warning: ?string, changed: bool}
     */
    public function replace(Order $order, string $key, ProductVariant $variant, int $quantity, ?float $price, User $user): array
    {
        $this->refuseLegacy($order);
        $shop = $this->shop($order);
        $line = $this->line($order, $key);
        $this->requireVariant($variant);

        return $this->edit($shop, $order, $user, 'replace', [
            'op' => 'replace', 'order_id' => $order->id, 'key' => $key, 'variant_id' => $variant->id, 'quantity' => $quantity,
        ], function (ShopifyClient $client, string $calcId, array $nodes) use ($line, $variant, $quantity) {
            $this->mutate($client, self::ADD_VARIANT, [
                'id' => $calcId,
                'variantId' => $this->gid('ProductVariant', $variant->shopify_variant_id),
                'quantity' => $quantity,
            ], 'orderEditAddVariant');
            $this->mutate($client, self::SET_QTY, [
                'id' => $calcId,
                'lineItemId' => $this->calculatedLineId($nodes, $line),
                'quantity' => 0,
            ], 'orderEditSetQuantity');
        }, 'Produit remplacé');
    }

    /** Address, phone, email and note. Local fields change only after Shopify accepts. */
    public function updateCustomer(Order $order, array $fields, User $user): Order
    {
        $shop = $this->shop($order);
        if (! ($shop->capabilities()['orders_write'] ?? false)) {
            throw ValidationException::withMessages(['shopify' => self::UNAUTHORIZED]);
        }

        $input = ['id' => $this->gid('Order', $order->shopify_order_id)];
        foreach (['email', 'note', 'phone'] as $key) {
            if (array_key_exists($key, $fields)) {
                $input[$key] = $fields[$key];
            }
        }
        if (isset($fields['shipping_address']) && is_array($fields['shipping_address'])) {
            $address = $fields['shipping_address'];
            $input['shippingAddress'] = array_filter([
                'address1' => $address['address1'] ?? ($address['address'] ?? null),
                'address2' => $address['address2'] ?? null,
                'city' => $address['city'] ?? null,
                'phone' => $address['phone'] ?? null,
                'zip' => $address['zip'] ?? null,
                'province' => $address['province'] ?? null,
                'country' => $address['country'] ?? null,
                'firstName' => $address['first_name'] ?? null,
                'lastName' => $address['last_name'] ?? null,
            ], fn ($v) => $v !== null);
        }

        $replay = ['op' => 'customer', 'order_id' => $order->id, 'fields' => $fields];
        try {
            $this->mutate($this->normalizer->client($shop), self::ORDER_UPDATE, ['input' => $input], 'orderUpdate');
        } catch (ShopifyApiException $e) {
            $this->fail($shop, $order, 'order_edit', $replay, $e, $user);

            throw ValidationException::withMessages(['shopify' => $e->getMessage()]);
        }

        $before = $order->only(['phone', 'email', 'note', 'shipping_address']);
        if (array_key_exists('phone', $fields)) {
            $order->phone = $fields['phone'];
        }
        if (array_key_exists('email', $fields)) {
            $order->email = $fields['email'];
        }
        if (array_key_exists('note', $fields)) {
            $order->note = $fields['note'];
        }
        if (isset($input['shippingAddress'])) {
            $current = (array) ($order->shipping_address ?? []);
            $order->shipping_address = array_merge($current, array_filter([
                'address1' => $input['shippingAddress']['address1'] ?? null,
                'city' => $input['shippingAddress']['city'] ?? null,
                'phone' => $input['shippingAddress']['phone'] ?? null,
            ], fn ($v) => $v !== null));
        }
        $order->shopify_sync_status = 'synced';
        $order->shopify_sync_error = null;
        $order->shopify_synced_at = now();
        $order->save();

        SyncEcho::remember($shop->id, 'order', (string) $order->shopify_order_id, $this->customerEcho($order), $order->shopify_updated_at?->toIso8601String());

        $this->logger->log([
            'company_id' => $shop->resolveCompanyId(),
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'shopify_id' => (string) $order->shopify_order_id,
            'action' => 'order_edit',
            'source' => 'flow_user',
            'user_id' => $user->id,
            'status' => 'success',
            'request_excerpt' => $replay,
        ]);
        $this->logger->fields($shop->resolveCompanyId(), $shop->id, 'order', $order->id, $before, $order->only(array_keys($before)), 'flow_user', null, false, $user->id);

        return $order->fresh();
    }

    /** Replays a failed outbound edit stored on a sync log. */
    public function replay(array $payload, ?User $user): void
    {
        $order = Order::query()->findOrFail($payload['order_id']);
        $user ??= User::query()->find($payload['user_id'] ?? 0) ?? $order->shop?->company?->users()->first();
        if (! $user) {
            throw new ShopifyApiException('Utilisateur introuvable pour relancer la modification.', 422, false);
        }
        match ($payload['op'] ?? '') {
            'add' => $this->add($order, ProductVariant::query()->findOrFail($payload['variant_id']), (int) $payload['quantity'], $payload['price'] ?? null, $user),
            'quantity' => $this->updateQuantity($order, (string) $payload['key'], (int) $payload['quantity'], $user),
            'price' => $this->updatePrice($order, (string) $payload['key'], (float) $payload['price'], $user),
            'remove' => $this->remove($order, (string) $payload['key'], $user),
            'replace' => $this->replace($order, (string) $payload['key'], ProductVariant::query()->findOrFail($payload['variant_id']), (int) $payload['quantity'], $payload['price'] ?? null, $user),
            'customer' => $this->updateCustomer($order, (array) ($payload['fields'] ?? []), $user),
            default => throw new ShopifyApiException('Opération Shopify inconnue.', 422, false),
        };
    }

    protected function shop(Order $order): ShopifyShop
    {
        $shop = $order->shop;
        if (! $this->isConnectedOrder($order) || ! $shop) {
            throw ValidationException::withMessages(['shopify' => 'Cette commande n’est pas liée à Shopify.']);
        }
        if (! ($shop->capabilities()['order_edit'] ?? false)) {
            throw ValidationException::withMessages(['shopify' => self::UNAUTHORIZED]);
        }

        return $shop;
    }

    /**
     * @param  callable(ShopifyClient, string, array): void  $mutations
     * @param  array<string, mixed>  $replay
     * @return array{order: Order, warning: ?string, changed: bool}
     */
    protected function edit(ShopifyShop $shop, Order $order, User $user, string $action, array $replay, callable $mutations, string $label): array
    {
        $this->refuseLegacy($order);
        $client = $this->normalizer->client($shop);
        $replay['user_id'] = $user->id;
        try {
            $begun = $this->mutate($client, self::BEGIN, [
                'id' => $this->gid('Order', $order->shopify_order_id),
            ], 'orderEditBegin');
            $calc = $begun['orderEditBegin']['calculatedOrder'] ?? [];
            $calcId = (string) ($calc['id'] ?? '');
            $nodes = $calc['lineItems']['nodes'] ?? [];
            if ($calcId === '') {
                throw new ShopifyApiException('Shopify n’a pas ouvert la modification.', 422, false);
            }
            $mutations($client, $calcId, $nodes);
            $committed = $this->mutate($client, self::COMMIT, [
                'id' => $calcId,
                'notifyCustomer' => false,
                'staffNote' => 'Modifié depuis Lav’Fast Flow par '.$user->name,
            ], 'orderEditCommit');
        } catch (ShopifyApiException $e) {
            $this->fail($shop, $order, 'order_edit', $replay, $e, $user);
            if (! $e->retryable) {
                throw ValidationException::withMessages(['shopify' => $e->getMessage()]);
            }
            throw $e;
        }

        $node = $committed['orderEditCommit']['order'] ?? null;
        if (! is_array($node)) {
            throw ValidationException::withMessages(['shopify' => 'Shopify n’a pas renvoyé la commande modifiée.']);
        }
        $rest = $this->normalizer->toRest($node);
        $updated = $this->sync->upsertFromShopifyPayload($shop, $rest, 'flow_user');
        $this->rememberOrderEcho($shop, $updated, true);

        OrderStatusHistory::create([
            'order_id' => $updated->id,
            'kind' => 'produits',
            'status_code' => 'items_'.$action,
            'status_name' => $label,
            'status_color' => '#7c3aed',
            'data' => ['action' => $action, 'scope' => 'shopify', 'shopify' => 'pushed'],
            'note' => $label.' depuis Lav’Fast Flow par '.$user->name,
            'user_id' => $user->id,
        ]);
        $this->logger->log([
            'company_id' => $shop->resolveCompanyId(),
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'order',
            'entity_id' => $updated->id,
            'shopify_id' => (string) $updated->shopify_order_id,
            'action' => 'order_edit',
            'source' => 'flow_user',
            'user_id' => $user->id,
            'status' => 'success',
            'request_excerpt' => $replay,
        ]);

        return ['order' => $updated->fresh(), 'warning' => null, 'changed' => true];
    }

    /**
     * Pushes a confirmation discount. The local total changes only from the committed order.
     *
     * @return array{order: Order, tag: string, ids: list<string>}
     */
    public function pushDiscount(Order $order, string $type, float $value, ?string $reason, User $user): array
    {
        $this->refuseLegacy($order);
        $shop = $this->shop($order);
        $shares = $this->discountShares($order, $type, $value);
        $tag = 'Remise Lav’Fast Flow · '.Str::uuid();
        $description = trim((string) $reason) !== '' ? trim((string) $reason).' · '.$tag : $tag;
        $ids = [];
        $updated = $this->commitOnly($shop, $order, $user, [
            'op' => 'discount', 'order_id' => $order->id, 'type' => $type, 'value' => $value, 'tag' => $tag, 'user_id' => $user->id,
        ], function (ShopifyClient $client, string $calcId, array $nodes) use ($shares, $type, $value, $description, $order, &$ids) {
            foreach ($shares as $share) {
                $discount = ['description' => $description];
                if ($type === 'percent') {
                    $discount['percentValue'] = $value;
                } else {
                    $discount['fixedValue'] = [
                        'amount' => number_format((float) $share['amount'], 2, '.', ''),
                        'currencyCode' => $order->currency ?: 'MAD',
                    ];
                }
                $result = $this->mutate($client, self::ADD_DISCOUNT, [
                    'id' => $calcId,
                    'lineItemId' => $this->calculatedLineId($nodes, $share['line']),
                    'discount' => $discount,
                ], 'orderEditAddLineItemDiscount');
                foreach (data_get($result, 'orderEditAddLineItemDiscount.calculatedOrder.addedDiscountApplications.nodes', []) as $node) {
                    if (! empty($node['id'])) {
                        $ids[] = (string) $node['id'];
                    }
                }
            }
        });

        return ['order' => $updated, 'tag' => $tag, 'ids' => array_values(array_unique($ids))];
    }

    /**
     * Stored value is either the new `{tag, ids}` object or a legacy list of calculated ids.
     *
     * @param  array<int|string, mixed>  $stored
     */
    public function removePushedDiscount(Order $order, array $stored, User $user): Order
    {
        $this->refuseLegacy($order);
        $record = $this->discountRecord($stored);
        if ($record['tag'] === null && $record['ids'] === []) {
            throw ValidationException::withMessages(['shopify' => 'Cette remise n’a pas d’identifiant Shopify.']);
        }
        $shop = $this->shop($order);

        return $this->commitOnly($shop, $order, $user, [
            'op' => 'discount_remove', 'order_id' => $order->id, 'tag' => $record['tag'], 'ids' => $record['ids'], 'user_id' => $user->id,
        ], function (ShopifyClient $client, string $calcId, array $nodes) use ($record) {
            $ids = $this->applicationIdsMatching($nodes, $record['tag']);
            if ($ids === []) {
                $ids = $record['ids'];
            }
            if ($ids === []) {
                throw ValidationException::withMessages(['shopify' => self::DISCOUNT_MISSING]);
            }
            foreach ($ids as $id) {
                $this->mutate($client, self::REMOVE_DISCOUNT, [
                    'id' => $calcId,
                    'discountApplicationId' => $id,
                ], 'orderEditRemoveDiscount');
            }
        });
    }

    /**
     * Drops the local freeze and copies the current Shopify order (lines, total, outstanding).
     */
    public function takeRemote(Order $order, User $user): Order
    {
        $shop = $order->shop;
        if (! $this->isConnectedOrder($order) || ! $shop) {
            throw ValidationException::withMessages(['shopify' => 'Cette commande n’est pas liée à Shopify.']);
        }
        if (! $order->items_edited_at) {
            throw ValidationException::withMessages(['shopify' => 'Cette commande n’a pas de modifications locales en conflit.']);
        }
        $rest = $this->normalizer->fetchRest($shop, (string) $order->shopify_order_id);
        if ($rest === null) {
            throw ValidationException::withMessages(['shopify' => 'Shopify n’a pas renvoyé la commande.']);
        }

        $beforeLines = $order->line_items ?? [];
        $beforeTotal = (float) $order->total_price;
        $order->forceFill(['items_edited_at' => null, 'items_edited_by' => null])->save();
        $updated = $this->sync->upsertFromShopifyPayload($shop, $rest, 'flow_user');
        $updated->forceFill([
            'items_edited_at' => null,
            'items_edited_by' => null,
            'shopify_sync_status' => 'synced',
            'shopify_sync_error' => null,
        ])->save();

        $afterLines = $updated->line_items ?? [];
        $afterTotal = (float) $updated->total_price;
        OrderStatusHistory::create([
            'order_id' => $updated->id,
            'kind' => 'shopify',
            'status_code' => 'shopify_take_remote',
            'status_name' => 'Version Shopify reprise',
            'status_color' => '#0369a1',
            'data' => [
                'lines_from' => $beforeLines,
                'lines_to' => $afterLines,
                'total_from' => $beforeTotal,
                'total_to' => $afterTotal,
            ],
            'note' => 'Version Shopify reprise — modifications locales remplacées · '
                .$this->lineList($beforeLines).' → '.$this->lineList($afterLines)
                .' · Total '.number_format($beforeTotal, 2, ',', ' ').' DH → '.number_format($afterTotal, 2, ',', ' ').' DH',
            'user_id' => $user->id,
        ]);

        return $updated->fresh();
    }

    protected function refuseLegacy(Order $order): void
    {
        if ($order->items_edited_at) {
            throw ValidationException::withMessages(['shopify' => self::LEGACY_CONFLICT]);
        }
    }

    /**
     * @param  array<int|string, mixed>  $stored
     * @return array{tag: ?string, ids: list<string>}
     */
    protected function discountRecord(array $stored): array
    {
        if ($stored === []) {
            return ['tag' => null, 'ids' => []];
        }
        if (array_is_list($stored)) {
            return ['tag' => null, 'ids' => array_values(array_filter(array_map('strval', $stored)))];
        }

        return [
            'tag' => isset($stored['tag']) && $stored['tag'] !== '' ? (string) $stored['tag'] : null,
            'ids' => array_values(array_filter(array_map('strval', (array) ($stored['ids'] ?? [])))),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    protected function applicationIdsMatching(array $nodes, ?string $tag): array
    {
        if ($tag === null || $tag === '') {
            return [];
        }
        $ids = [];
        foreach ($nodes as $node) {
            foreach ($node['calculatedDiscountAllocations'] ?? [] as $allocation) {
                $application = $allocation['discountApplication'] ?? [];
                $description = (string) ($application['description'] ?? '');
                if ($description !== '' && str_contains($description, $tag) && ! empty($application['id'])) {
                    $ids[] = (string) $application['id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param  list<array<string, mixed>>|array<int, mixed>  $lines */
    protected function lineList(array $lines): string
    {
        $parts = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $title = trim((string) ($line['title'] ?? 'Produit'));
            $parts[] = ($title !== '' ? $title : 'Produit').' ×'.(int) ($line['quantity'] ?? 1);
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /**
     * @param  callable(ShopifyClient, string, array): void  $mutations
     * @param  array<string, mixed>  $replay
     */
    protected function commitOnly(ShopifyShop $shop, Order $order, User $user, array $replay, callable $mutations): Order
    {
        $done = $this->edit($shop, $order, $user, 'discount', $replay, $mutations, 'Remise');

        return $done['order'];
    }

    /** @return array<string, mixed> */
    protected function customerEcho(Order $order): array
    {
        return [
            'phone' => $order->phone,
            'email' => $order->email,
            'note' => $order->note,
            'shipping_address' => $order->shipping_address,
        ];
    }

    protected function rememberOrderEcho(ShopifyShop $shop, Order $order, bool $withLines): void
    {
        $fields = $this->customerEcho($order);
        if ($withLines) {
            $fields['line_items'] = SyncEcho::lineFingerprint($order->line_items ?? []);
            $fields['total_price'] = number_format((float) $order->total_price, 2, '.', '');
            $fields['financial_status'] = $order->financial_status;
        }
        SyncEcho::remember(
            $shop->id,
            'order',
            (string) $order->shopify_order_id,
            $fields,
            $order->shopify_updated_at?->toIso8601String(),
        );
    }

    /**
     * Amount discounts are split across lines so the rounded shares sum to the exact value.
     * Percent discounts apply percentValue on every priced line.
     *
     * @return list<array{line: array<string, mixed>, amount: ?float}>
     */
    protected function discountShares(Order $order, string $type, float $value): array
    {
        $lines = array_values(array_filter(
            OrderLines::normalize($order->line_items),
            fn ($line) => (int) ($line['quantity'] ?? 0) > 0 && (float) ($line['price'] ?? 0) > 0,
        ));
        if ($lines === []) {
            throw ValidationException::withMessages(['value' => 'Aucune ligne à remiser.']);
        }
        if ($type === 'percent') {
            return array_map(fn ($line) => ['line' => $line, 'amount' => null], $lines);
        }
        $subs = array_map(fn ($line) => round((float) $line['price'] * (int) $line['quantity'], 2), $lines);
        $sum = array_sum($subs);
        if ($value > $sum + 0.001) {
            throw ValidationException::withMessages(['value' => 'La remise dépasse le total de la commande.']);
        }
        $shares = [];
        $allocated = 0.0;
        foreach ($lines as $i => $line) {
            $amount = $i === count($lines) - 1
                ? round($value - $allocated, 2)
                : round($value * $subs[$i] / $sum, 2);
            $allocated += $amount;
            if ($amount > 0) {
                $shares[] = ['line' => $line, 'amount' => $amount];
            }
        }

        return $shares;
    }

    /** @param  array<string, mixed>  $replay */
    protected function fail(ShopifyShop $shop, Order $order, string $action, array $replay, ShopifyApiException $e, ?User $user): void
    {
        $order->forceFill([
            'shopify_sync_status' => 'failed',
            'shopify_sync_error' => mb_substr($e->getMessage(), 0, 500),
        ])->save();
        $this->logger->log([
            'company_id' => $shop->resolveCompanyId(),
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'shopify_id' => (string) $order->shopify_order_id,
            'action' => $action,
            'source' => 'flow_user',
            'user_id' => $user?->id,
            'status' => 'failed',
            'error' => $e->getMessage(),
            'attempts' => 1,
            'request_excerpt' => $replay,
        ]);
    }

    /** @param  array<string, mixed>  $variables */
    protected function mutate(ShopifyClient $client, string $query, array $variables, string $key): array
    {
        return $client->graphqlMutation($query, $variables, $key);
    }

    /** @return array<string, mixed> */
    protected function line(Order $order, string $key): array
    {
        $lines = OrderLines::normalize($order->line_items);
        foreach ($lines as $line) {
            if ((string) $line['key'] === $key) {
                return $line;
            }
        }
        throw ValidationException::withMessages(['line' => 'Ligne de commande introuvable.']);
    }

    /** @param  list<array<string, mixed>>  $nodes */
    protected function calculatedLineId(array $nodes, array $line): string
    {
        $variantId = (string) ($line['variant_id'] ?? '');
        foreach ($nodes as $node) {
            $gid = (string) data_get($node, 'variant.legacyResourceId', data_get($node, 'variant.id'));
            $numeric = str_contains($gid, '/') ? substr($gid, strrpos($gid, '/') + 1) : $gid;
            if ($variantId !== '' && (string) $numeric === $variantId) {
                return (string) $node['id'];
            }
        }
        $lineId = (string) ($line['id'] ?? '');
        foreach ($nodes as $node) {
            $gid = (string) ($node['id'] ?? '');
            if ($lineId !== '' && str_ends_with($gid, '/'.$lineId)) {
                return $gid;
            }
        }
        throw new ShopifyApiException('Ligne Shopify introuvable pour cette modification.', 422, false);
    }

    protected function requireVariant(ProductVariant $variant): void
    {
        if (! $variant->shopify_variant_id) {
            throw ValidationException::withMessages(['variant_id' => 'Cette variante n’est pas liée à Shopify.']);
        }
    }

    protected function gid(string $type, int|string $id): string
    {
        return "gid://shopify/{$type}/{$id}";
    }

    private const BEGIN = <<<'GQL'
mutation orderEditBegin($id: ID!) {
  orderEditBegin(id: $id) {
    calculatedOrder {
      id
      lineItems(first: 60) {
        nodes {
          id quantity
          variant { id legacyResourceId }
          calculatedDiscountAllocations { discountApplication { id description } }
        }
      }
    }
    userErrors { field message }
  }
}
GQL;

    private const ADD_VARIANT = <<<'GQL'
mutation orderEditAddVariant($id: ID!, $variantId: ID!, $quantity: Int!) {
  orderEditAddVariant(id: $id, variantId: $variantId, quantity: $quantity) {
    calculatedLineItem { id }
    userErrors { field message }
  }
}
GQL;

    private const SET_QTY = <<<'GQL'
mutation orderEditSetQuantity($id: ID!, $lineItemId: ID!, $quantity: Int!) {
  orderEditSetQuantity(id: $id, lineItemId: $lineItemId, quantity: $quantity) {
    calculatedOrder { id }
    userErrors { field message }
  }
}
GQL;

    private const ADD_DISCOUNT = <<<'GQL'
mutation orderEditAddLineItemDiscount($id: ID!, $lineItemId: ID!, $discount: OrderEditAppliedDiscountInput!) {
  orderEditAddLineItemDiscount(id: $id, lineItemId: $lineItemId, discount: $discount) {
    calculatedOrder { addedDiscountApplications(first: 20) { nodes { id } } }
    calculatedLineItem { id }
    userErrors { field message }
  }
}
GQL;

    private const REMOVE_DISCOUNT = <<<'GQL'
mutation orderEditRemoveDiscount($id: ID!, $discountApplicationId: ID!) {
  orderEditRemoveDiscount(id: $id, discountApplicationId: $discountApplicationId) {
    calculatedOrder { id }
    userErrors { field message }
  }
}
GQL;

    private const COMMIT = <<<'GQL'
mutation orderEditCommit($id: ID!, $notifyCustomer: Boolean, $staffNote: String) {
  orderEditCommit(id: $id, notifyCustomer: $notifyCustomer, staffNote: $staffNote) {
    order {
      legacyResourceId name email phone note tags
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
    userErrors { field message }
  }
}
GQL;

    private const ORDER_UPDATE = <<<'GQL'
mutation orderUpdate($input: OrderInput!) {
  orderUpdate(input: $input) {
    order { id legacyResourceId }
    userErrors { field message }
  }
}
GQL;
}
