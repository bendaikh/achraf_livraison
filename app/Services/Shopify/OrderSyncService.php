<?php

namespace App\Services\Shopify;

use App\Models\ConfirmationStatus;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderStatusHistory;
use App\Models\ShopifyShop;
use App\Services\Automations\AutomationDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class OrderSyncService
{
    public string $lastOutcome = 'processed';

    public function __construct(private readonly ShopifySyncLogger $logger) {}

    public function upsertFromShopifyPayload(ShopifyShop $shop, array $order, string $source = 'webhook'): Order
    {
        return SyncContext::inbound(function () use ($shop, $order, $source) {
            $companyId = $shop->resolveCompanyId();
            $shipping = $order['shipping_address'] ?? null;
            $customer = $order['customer'] ?? [];
            $customerName = trim(implode(' ', array_filter([
                $customer['first_name'] ?? ($shipping['first_name'] ?? null),
                $customer['last_name'] ?? ($shipping['last_name'] ?? null),
            ])));
            $phone = $order['phone'] ?? ($shipping['phone'] ?? null) ?? ($customer['phone'] ?? null);

            $lineItems = collect($order['line_items'] ?? [])->map(function (array $item) {
                $sold = $item['price'] ?? null;
                $catalog = $item['catalog_price'] ?? null;

                return [
                    'id' => $item['id'] ?? null,
                    'title' => $item['title'] ?? null,
                    'variant_title' => $item['variant_title'] ?? null,
                    'quantity' => $item['quantity'] ?? 0,
                    'sku' => $item['sku'] ?? null,
                    'price' => $sold,
                    'catalog_price' => $catalog,
                    'product_id' => $item['product_id'] ?? null,
                    'variant_id' => $item['variant_id'] ?? null,
                ];
            })->values()->all();

            $existing = Order::query()
                ->where('shopify_shop_id', $shop->id)
                ->where('shopify_order_id', (int) $order['id'])
                ->first();

            $matchedCreationKey = false;
            if (! $existing && ($creationKey = self::creationKeyFromPayload($order))) {
                $pending = Order::query()
                    ->where('company_id', $companyId)
                    ->where('creation_key', $creationKey)
                    ->first();
                if ($pending) {
                    if ((int) $pending->shopify_order_id !== (int) $order['id'] || (int) $pending->shopify_shop_id !== (int) $shop->id) {
                        $pending->forceFill([
                            'shopify_shop_id' => $shop->id,
                            'shopify_order_id' => (int) $order['id'],
                        ])->save();
                        $pending = $pending->fresh();
                    }
                    $existing = $pending;
                    $matchedCreationKey = true;
                }
            }

            $incomingAt = $order['updated_at'] ?? null;
            if ($existing && $incomingAt && $existing->shopify_updated_at && Carbon::parse($incomingAt)->lt($existing->shopify_updated_at)) {
                $this->lastOutcome = 'ignored';
                $this->logger->log([
                    'company_id' => $companyId,
                    'shopify_shop_id' => $shop->id,
                    'direction' => 'in',
                    'entity_type' => 'order',
                    'entity_id' => $existing->id,
                    'shopify_id' => (string) $order['id'],
                    'action' => 'upsert',
                    'source' => $source,
                    'status' => 'ignored',
                    'error' => 'Payload plus ancien que shopify_updated_at local ('.$existing->shopify_updated_at->toIso8601String().').',
                    'request_excerpt' => ['id' => $order['id'] ?? null, 'updated_at' => $incomingAt],
                ]);

                return $existing;
            }

            $outstanding = $this->outstanding($order);
            $total = $order['total_price'] ?? 0;
            $amountPaid = $this->amountPaid($order, $outstanding, $total);

            $attributes = [
                'company_id' => $companyId,
                'order_number' => (string) ($order['order_number'] ?? $order['name'] ?? $order['id']),
                'name' => $order['name'] ?? null,
                'email' => $order['email'] ?? ($customer['email'] ?? null),
                'phone' => $phone,
                'customer_name' => $customerName !== '' ? $customerName : null,
                'financial_status' => $order['financial_status'] ?? null,
                'fulfillment_status' => $order['fulfillment_status'] ?? null,
                'status' => $this->mapLocalStatus($order),
                'total_price' => $total,
                'shipping_price' => $this->extractShippingPrice($order),
                'currency' => $order['currency'] ?? $shop->currency,
                'shipping_address' => $shipping,
                'line_items' => $lineItems,
                'note' => $order['note'] ?? null,
                'shopify_created_at' => $this->parseDate($order['created_at'] ?? null),
                'shopify_updated_at' => $this->parseDate($incomingAt),
                'shopify_customer_id' => isset($customer['id']) ? (int) $customer['id'] : null,
                'tags' => $this->tags($order),
                'discount_applications' => $order['discount_applications'] ?? null,
                'payment_gateway_names' => $order['payment_gateway_names'] ?? null,
                'total_outstanding' => $outstanding,
                'amount_paid' => $amountPaid,
                'shopify_sync_error' => null,
                'shopify_synced_at' => now(),
            ];
            if (isset($order['total_discounts'])) {
                $attributes['discount_total'] = $order['total_discounts'];
            }
            if (isset($order['refunds']) && is_array($order['refunds'])) {
                $attributes['shopify_refunds'] = $order['refunds'];
            }

            $attributes['shopify_line_items'] = $lineItems;
            $legacyConflict = (bool) $existing?->items_edited_at;
            if ($legacyConflict) {
                unset($attributes['line_items'], $attributes['total_price']);
            }

            $echoFields = [
                'phone' => $attributes['phone'] ?? null,
                'email' => $attributes['email'] ?? null,
                'note' => $attributes['note'] ?? null,
                'shipping_address' => $attributes['shipping_address'] ?? null,
                'line_items' => SyncEcho::lineFingerprint($lineItems),
                'total_price' => number_format((float) ($attributes['total_price'] ?? 0), 2, '.', ''),
                'financial_status' => $attributes['financial_status'] ?? null,
            ];
            $matchedEcho = SyncEcho::matchingFields($shop->id, 'order', (string) $order['id'], $echoFields);
            $isEcho = $matchedEcho !== null || $matchedCreationKey;
            $incomingCancel = ! empty($order['cancelled_at']);
            $pendingConflict = $existing && in_array($existing->shopify_sync_status, ['pending', 'failed'], true) && ! $incomingCancel;
            $attributes['shopify_sync_status'] = ($legacyConflict || $pendingConflict) && ! $isEcho ? 'conflict' : 'synced';
            if ($matchedCreationKey) {
                $attributes['flow_state'] = 'created';
            }

            if (! $existing) {
                $attributes['confirmation_status'] = ConfirmationStatus::defaultCode($shop->resolveCompanyId());
                $attributes['confirmation_history'] = [[
                    'type' => 'received',
                    'label' => 'Commande reçue depuis Shopify',
                    'user_id' => null,
                    'user_name' => null,
                    'at' => now()->toIso8601String(),
                ]];
            }

            $before = $existing ? $existing->only(array_keys($attributes)) : [];
            $trackedId = $existing?->id;
            try {
                if ($isEcho && $existing) {
                    SyncContext::suppressOrder($existing->id, $matchedEcho ?? true);
                }

                $local = Order::updateOrCreate(
                    ['shopify_shop_id' => $shop->id, 'shopify_order_id' => (int) $order['id']],
                    $attributes,
                );
                $trackedId = $local->id;

                $this->syncFulfillments($shop, $local, $order['fulfillments'] ?? [], $source);
                $this->writeTimeline($local, $before, $legacyConflict, $isEcho);

                $log = $this->logger->log([
                    'company_id' => $companyId,
                    'shopify_shop_id' => $shop->id,
                    'direction' => 'in',
                    'entity_type' => 'order',
                    'entity_id' => $local->id,
                    'shopify_id' => (string) $order['id'],
                    'action' => $existing ? 'update' : 'create',
                    'source' => $source,
                    'status' => $isEcho ? 'echo' : 'success',
                    'request_excerpt' => Arr::only($order, ['id', 'name', 'updated_at', 'financial_status', 'fulfillment_status']),
                ]);
                $this->logger->fields(
                    $companyId,
                    $shop->id,
                    'order',
                    $local->id,
                    $before,
                    Arr::only($local->only(array_keys($attributes)), array_keys($before ?: $attributes)),
                    'shopify',
                    $log->id,
                    (bool) ($legacyConflict || $pendingConflict),
                );

                if ($source === 'flow_user') {
                    SyncEcho::remember($shop->id, 'order', (string) $order['id'], $echoFields, is_string($incomingAt) ? $incomingAt : null);
                }

                if (! $isEcho && $source !== 'flow_user') {
                    $trigger = $source === 'webhook' && ($order['_topic'] ?? '') === 'orders/edited'
                        ? 'shopify.order_edited'
                        : 'shopify.order_updated';
                    app(AutomationDispatcher::class)->dispatch(
                        $trigger,
                        $companyId,
                        $local,
                        ['order_id' => $local->id, 'shopify_id' => (string) $order['id']],
                        $trigger.':'.$local->id.':'.md5((string) ($incomingAt ?? $local->updated_at)),
                    );
                }

                $this->lastOutcome = $isEcho ? 'echo' : 'processed';

                return $local->fresh();
            } finally {
                if ($trackedId) {
                    SyncContext::clearSuppressed($trackedId);
                }
            }
        });
    }

    public function syncRecentOrders(ShopifyShop $shop, int $limit = 50): int
    {
        $apiVersion = app(ShopifyOAuth::class)->apiVersion();
        $client = new ShopifyClient($shop->shop_domain, $shop->access_token, $apiVersion);
        $payload = $client->get('orders.json', [
            'status' => 'any',
            'limit' => min($limit, 250),
            'order' => 'created_at desc',
        ]);

        $count = 0;
        foreach ($payload['orders'] ?? [] as $order) {
            $this->upsertFromShopifyPayload($shop, $order, 'manual');
            $count++;
        }

        $shop->forceFill(['last_synced_at' => now()])->save();

        return $count;
    }

    public function markCancelled(ShopifyShop $shop, array $order): Order
    {
        $order['cancelled_at'] = $order['cancelled_at'] ?? now()->toIso8601String();
        $local = $this->upsertFromShopifyPayload($shop, $order);
        if ($local->status !== 'cancelled') {
            $local->forceFill(['status' => 'cancelled'])->save();
        }

        return $local;
    }

    /** customers/update: refresh name, email and phone on this shop's orders. */
    public function applyCustomerUpdate(ShopifyShop $shop, array $customer, string $source = 'webhook'): int
    {
        $id = (int) ($customer['id'] ?? 0);
        if (! $id) {
            return 0;
        }
        $name = trim(implode(' ', array_filter([$customer['first_name'] ?? null, $customer['last_name'] ?? null])));
        $orders = Order::query()->where('shopify_shop_id', $shop->id)->where('shopify_customer_id', $id)->get();
        foreach ($orders as $order) {
            $before = $order->only(['customer_name', 'email', 'phone']);
            $order->forceFill([
                'customer_name' => $name !== '' ? $name : $order->customer_name,
                'email' => $customer['email'] ?? $order->email,
                'phone' => $customer['phone'] ?? $order->phone,
            ])->save();
            $this->writeTimeline($order->fresh(), $before, false, false);
        }

        return $orders->count();
    }

    /** refunds/create: store the refund. Outstanding follows the payload when Shopify sends it. */
    public function applyRefund(ShopifyShop $shop, array $refund, string $source = 'webhook'): ?Order
    {
        $orderId = (int) ($refund['order_id'] ?? 0);
        if (! $orderId) {
            return null;
        }
        $local = Order::query()->where('shopify_shop_id', $shop->id)->where('shopify_order_id', $orderId)->first();
        if (! $local) {
            return null;
        }
        $refunds = $local->shopify_refunds ?? [];
        $refunds[] = Arr::only($refund, ['id', 'order_id', 'created_at', 'note', 'transactions', 'refund_line_items']);
        $fill = ['shopify_refunds' => $refunds];
        if (isset($refund['order']) && is_array($refund['order'])) {
            return $this->upsertFromShopifyPayload($shop, $refund['order'] + ['id' => $orderId], $source);
        }
        $local->forceFill($fill)->save();
        OrderStatusHistory::create([
            'order_id' => $local->id,
            'kind' => 'shopify',
            'status_code' => 'shopify_refund',
            'status_name' => 'Shopify',
            'status_color' => '#0369a1',
            'note' => 'Shopify : remboursement enregistré',
            'data' => ['refund_id' => $refund['id'] ?? null],
        ]);

        return $local;
    }

    /** @param  list<array<string, mixed>>  $fulfillments */
    public function syncFulfillments(ShopifyShop $shop, Order $order, array $fulfillments, string $source = 'webhook'): void
    {
        foreach ($fulfillments as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $this->upsertFulfillment($shop, $order, $row, $source);
        }
    }

    public function upsertFulfillmentFromWebhook(ShopifyShop $shop, array $payload, string $source = 'webhook'): ?OrderFulfillment
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $order = Order::query()->where('shopify_shop_id', $shop->id)->where('shopify_order_id', $orderId)->first();
        if (! $order) {
            return null;
        }

        return $this->upsertFulfillment($shop, $order, $payload, $source);
    }

    public function upsertFulfillment(ShopifyShop $shop, Order $order, array $row, string $source = 'webhook'): OrderFulfillment
    {
        $tracking = $this->tracking($row);
        $existing = OrderFulfillment::query()
            ->where('order_id', $order->id)
            ->where('shopify_fulfillment_id', (int) $row['id'])
            ->first();
        $beforeNumber = $existing?->tracking_number;

        $fulfillment = OrderFulfillment::query()->updateOrCreate(
            ['order_id' => $order->id, 'shopify_fulfillment_id' => (int) $row['id']],
            [
                'company_id' => $order->company_id ?: $shop->resolveCompanyId(),
                'status' => isset($row['status']) ? strtolower((string) $row['status']) : null,
                'tracking_number' => $tracking['number'],
                'tracking_company' => $tracking['company'],
                'tracking_url' => $tracking['url'],
                'source' => 'shopify',
                'shopify_synced_at' => now(),
            ],
        );

        if ($tracking['number'] && $tracking['number'] !== $beforeNumber) {
            $verb = $beforeNumber ? 'modifié' : 'ajouté';
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'kind' => 'shopify',
                'status_code' => 'shopify_tracking',
                'status_name' => 'Shopify',
                'status_color' => '#0369a1',
                'note' => 'Shopify : tracking '.$verb.' '.$tracking['number'],
                'data' => $tracking,
            ]);
            $companyId = (int) ($order->company_id ?: $shop->resolveCompanyId());
            app(AutomationDispatcher::class)->dispatch(
                'shopify.tracking_received',
                $companyId,
                $order,
                ['order_id' => $order->id, 'tracking_number' => $tracking['number'], 'tracking_company' => $tracking['company']],
                'shopify.tracking_received:'.$fulfillment->id.':'.$tracking['number'],
            );
        }
        if (! $existing) {
            app(AutomationDispatcher::class)->dispatch(
                'shopify.fulfillment_created',
                (int) ($order->company_id ?: $shop->resolveCompanyId()),
                $order,
                ['order_id' => $order->id, 'fulfillment_id' => $fulfillment->id],
                'shopify.fulfillment_created:'.$fulfillment->id,
            );
        }

        return $fulfillment;
    }

    private function writeTimeline(Order $order, array $before, bool $legacyConflict, bool $echo): void
    {
        if ($before === []) {
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'kind' => 'shopify',
                'status_code' => 'shopify_received',
                'status_name' => 'Shopify',
                'status_color' => '#0369a1',
                'note' => 'Shopify : commande reçue',
            ]);

            return;
        }
        if ($echo) {
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'kind' => 'shopify',
                'status_code' => 'shopify_echo',
                'status_name' => 'Shopify',
                'status_color' => '#0369a1',
                'note' => 'Shopify : modification synchronisée (écho)',
            ]);

            return;
        }

        $notes = [];
        if (array_key_exists('phone', $before) && (string) $before['phone'] !== (string) $order->phone) {
            $notes[] = 'Shopify : téléphone '.$this->clip($before['phone']).' → '.$this->clip($order->phone);
        }
        if (array_key_exists('email', $before) && (string) ($before['email'] ?? '') !== (string) ($order->email ?? '')) {
            $notes[] = 'Shopify : email '.$this->clip($before['email']).' → '.$this->clip($order->email);
        }
        if (array_key_exists('shipping_address', $before) && json_encode($before['shipping_address']) !== json_encode($order->shipping_address)) {
            $notes[] = 'Shopify : adresse modifiée';
        }
        if (array_key_exists('note', $before) && (string) ($before['note'] ?? '') !== (string) ($order->note ?? '')) {
            $notes[] = 'Shopify : note modifiée';
        }
        if (array_key_exists('customer_name', $before) && (string) ($before['customer_name'] ?? '') !== (string) ($order->customer_name ?? '')) {
            $notes[] = 'Shopify : client '.$this->clip($before['customer_name']).' → '.$this->clip($order->customer_name);
        }
        if ($legacyConflict && array_key_exists('line_items', $before)) {
            $notes[] = 'Shopify : conflit sur les produits (modification locale conservée)';
        } elseif (array_key_exists('line_items', $before) && json_encode($before['line_items']) !== json_encode($order->line_items)) {
            $notes[] = $this->lineNote($before['line_items'], $order->line_items);
        }

        foreach ($notes as $note) {
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'kind' => 'shopify',
                'status_code' => 'shopify_change',
                'status_name' => 'Shopify',
                'status_color' => '#0369a1',
                'note' => $note,
                'data' => ['conflict' => $legacyConflict],
            ]);
        }
    }

    private function lineNote(mixed $before, mixed $after): string
    {
        $old = collect(is_array($before) ? $before : [])->map(fn ($l) => trim(($l['title'] ?? '').' '.($l['variant_title'] ?? '')))->filter()->values();
        $new = collect(is_array($after) ? $after : [])->map(fn ($l) => trim(($l['title'] ?? '').' '.($l['variant_title'] ?? '')))->filter()->values();
        $removed = $old->diff($new)->first();
        $added = $new->diff($old)->first();
        if ($removed && $added) {
            return 'Shopify : produit remplacé '.$removed.' → '.$added;
        }
        if ($added && ! $removed) {
            return 'Shopify : produit ajouté '.$added;
        }
        if ($removed && ! $added) {
            return 'Shopify : produit retiré '.$removed;
        }

        return 'Shopify : produits modifiés';
    }

    private function clip(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '—';
        }

        return mb_strlen($text) > 12 ? mb_substr($text, 0, 4).'…' : $text;
    }

    /** @return array{number:?string,company:?string,url:?string} */
    private function tracking(array $row): array
    {
        $number = $row['tracking_number'] ?? ($row['tracking_numbers'][0] ?? null);
        $company = $row['tracking_company'] ?? null;
        $url = $row['tracking_url'] ?? ($row['tracking_urls'][0] ?? null);
        if (isset($row['tracking_info']) && is_array($row['tracking_info'])) {
            $number = $number ?: ($row['tracking_info']['number'] ?? null);
            $company = $company ?: ($row['tracking_info']['company'] ?? null);
            $url = $url ?: ($row['tracking_info']['url'] ?? null);
        }

        return [
            'number' => $number ? (string) $number : null,
            'company' => $company ? (string) $company : null,
            'url' => $url ? (string) $url : null,
        ];
    }

    /** lavfast_creation_key from note attributes, source_identifier, or a UUID tag. */
    public static function creationKeyFromPayload(array $order): ?string
    {
        $attrs = $order['note_attributes'] ?? [];
        if (is_array($attrs)) {
            if (function_exists('array_is_list') && array_is_list($attrs)) {
                foreach ($attrs as $attr) {
                    if (! is_array($attr)) {
                        continue;
                    }
                    $name = $attr['name'] ?? $attr['key'] ?? null;
                    if ($name === 'lavfast_creation_key' && filled($attr['value'] ?? null)) {
                        return (string) $attr['value'];
                    }
                }
            } elseif (filled($attrs['lavfast_creation_key'] ?? null)) {
                return (string) $attrs['lavfast_creation_key'];
            }
        }
        if (filled($order['source_identifier'] ?? null)) {
            return (string) $order['source_identifier'];
        }
        $tags = $order['tags'] ?? [];
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }
        foreach ((array) $tags as $tag) {
            if (is_string($tag) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tag)) {
                return $tag;
            }
        }

        return null;
    }

    private function outstanding(array $order): ?float
    {
        if (array_key_exists('total_outstanding', $order) && $order['total_outstanding'] !== null && $order['total_outstanding'] !== '') {
            return round((float) $order['total_outstanding'], 2);
        }
        if (($order['financial_status'] ?? null) === 'paid') {
            return 0.0;
        }

        return null;
    }

    private function amountPaid(array $order, ?float $outstanding, mixed $total): float
    {
        if (($order['financial_status'] ?? null) === 'paid') {
            return round((float) $total, 2);
        }
        if ($outstanding !== null) {
            return round(max(0, (float) $total - $outstanding), 2);
        }

        return 0.0;
    }

    private function tags(array $order): ?string
    {
        $tags = $order['tags'] ?? null;
        if (is_array($tags)) {
            return implode(', ', $tags);
        }

        return $tags !== null ? (string) $tags : null;
    }

    private function mapLocalStatus(array $order): string
    {
        if (! empty($order['cancelled_at'])) {
            return 'cancelled';
        }
        $fulfillment = $order['fulfillment_status'] ?? null;

        return match ($fulfillment) {
            'fulfilled' => 'fulfilled',
            'partial' => 'processing',
            default => 'pending',
        };
    }

    private function extractShippingPrice(array $order): ?float
    {
        $fromSet = data_get($order, 'total_shipping_price_set.shop_money.amount');
        if ($fromSet !== null && $fromSet !== '') {
            return (float) $fromSet;
        }
        $lines = $order['shipping_lines'] ?? [];
        if (! is_array($lines) || $lines === []) {
            return null;
        }

        return (float) collect($lines)->sum(fn ($line) => (float) ($line['price'] ?? 0));
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value);
    }
}
