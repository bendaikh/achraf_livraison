<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShopifyShop;
use App\Models\ShopifySyncLog;
use App\Models\User;
use App\Services\Shopify\OrderSyncService;
use App\Services\Shopify\ShopifyOrderNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCreateTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_cod_order_is_created_in_shopify_and_flow(): void
    {
        $admin = $this->signInAdmin();
        $shop = $this->shop();
        [$a, $b] = $this->variants();
        $key = (string) Str::uuid();
        $sent = null;
        $this->http(function ($request) use (&$sent, $key) {
            $sent = $this->gql($request);
            $body = $sent;
            if (! str_contains($body['query'] ?? '', 'orderCreate')) {
                return Http::response(['data' => ['orders' => ['nodes' => []]]]);
            }

            return Http::response(['data' => ['orderCreate' => [
                'userErrors' => [],
                'order' => $this->node(9100, '#9100', 'PENDING', '275.00', '275.00', '20.00', '30.00'),
            ]]]);
        });

        $res = $this->postJson('/api/orders/flow', $this->payload($key, $a, $b, [
            'commercial_user_id' => $admin->id,
            'internal_note' => 'Appel WhatsApp',
            'note' => 'Sonner à l’étage',
        ]))->assertCreated();

        $orderInput = $sent['variables']['order'];
        $this->assertSame('PENDING', $orderInput['financialStatus']);
        $this->assertSame('SALE', $orderInput['transactions'][0]['kind']);
        $this->assertSame('PENDING', $orderInput['transactions'][0]['status']);
        $this->assertSame('Cash on Delivery', $orderInput['transactions'][0]['gateway']);
        $this->assertSame('Livraison', $orderInput['shippingLines'][0]['title']);
        $this->assertSame('30.00', $orderInput['shippingLines'][0]['priceSet']['shopMoney']['amount']);
        $this->assertSame('Emballage', $orderInput['lineItems'][2]['title']);
        $this->assertFalse($orderInput['lineItems'][2]['requiresShipping']);
        $this->assertSame('gid://shopify/ProductVariant/111', $orderInput['lineItems'][0]['variantId']);
        $this->assertSame(2, $orderInput['lineItems'][0]['quantity']);
        $this->assertArrayNotHasKey('priceSet', $orderInput['lineItems'][0]);
        $this->assertSame('20.00', $orderInput['discountCode']['itemFixedDiscountCode']['amountSet']['shopMoney']['amount']);
        $this->assertContains('lavfast-flow', $orderInput['tags']);
        $this->assertContains($key, $orderInput['tags']);
        $this->assertSame($key, $orderInput['sourceIdentifier']);
        $this->assertSame($key, $orderInput['customAttributes'][0]['value']);
        $this->assertFalse($sent['variables']['options']['sendReceipt']);
        $this->assertFalse($sent['variables']['options']['sendFulfillmentReceipt']);
        $this->assertSame('DECREMENT_OBEYING_POLICY', $sent['variables']['options']['inventoryBehaviour']);
        $this->assertSame('+212612345678', $orderInput['shippingAddress']['phone']);
        $this->assertSame('MA', $orderInput['shippingAddress']['countryCode']);

        $order = Order::findOrFail($res->json('data.id'));
        $this->assertSame('Lav’Fast Flow', $order->source);
        $this->assertSame($admin->id, $order->created_by);
        $this->assertSame($admin->id, $order->commercial_user_id);
        $this->assertSame(9100, (int) $order->shopify_order_id);
        $this->assertSame($shop->id, $order->shopify_shop_id);
        $this->assertEquals(275, (float) $order->total_price);
        $this->assertEquals(275, (float) $order->amount_due);
        $this->assertSame('created', $order->flow_state);
        $this->assertSame('synced', $order->shopify_sync_status);
        $this->assertSame('Appel WhatsApp', $order->internal_note);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'kind' => 'commande', 'status_code' => 'created_in_flow']);
    }

    public function test_paid_and_partial_amounts(): void
    {
        $this->signInAdmin();
        $this->shop();
        [$a, $b] = $this->variants();

        $this->http(fn () => Http::response(['data' => ['orderCreate' => [
            'userErrors' => [],
            'order' => $this->node(9101, '#9101', 'PAID', '275.00', '0.00', '20.00', '30.00'),
        ]]]));
        $paid = $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b, [
            'payment_method' => 'paye',
            'payment_label' => 'Carte',
        ]))->assertCreated();
        $this->assertEquals(0, $paid->json('data.amount_due'));
        Http::assertSent(fn ($r) => ($this->gql($r)['variables']['order']['financialStatus'] ?? null) === 'PAID'
            && ($this->gql($r)['variables']['order']['transactions'][0]['status'] ?? null) === 'SUCCESS'
            && ($this->gql($r)['variables']['order']['transactions'][0]['gateway'] ?? null) === 'Carte');

        $this->http(fn () => Http::response(['data' => ['orderCreate' => [
            'userErrors' => [],
            'order' => $this->node(9102, '#9102', 'PARTIALLY_PAID', '275.00', '175.00', '20.00', '30.00'),
        ]]]));
        $partial = $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b, [
            'payment_method' => 'paye',
            'amount_paid' => 100,
            'payment_label' => 'Carte',
        ]))->assertCreated();
        $this->assertEquals(175, $partial->json('data.amount_due'));
        Http::assertSent(function ($r) {
            $tx = $this->gql($r)['variables']['order']['transactions'] ?? [];

            return count($tx) === 2 && $tx[0]['status'] === 'SUCCESS' && $tx[1]['status'] === 'PENDING';
        });
    }

    public function test_same_creation_key_creates_one_order(): void
    {
        $this->signInAdmin();
        $this->shop();
        [$a, $b] = $this->variants();
        $key = (string) Str::uuid();
        $this->http(fn () => Http::response(['data' => ['orderCreate' => [
            'userErrors' => [],
            'order' => $this->node(9200, '#9200', 'PENDING', '275.00', '275.00', '20.00', '30.00'),
        ]]]));

        $first = $this->postJson('/api/orders/flow', $this->payload($key, $a, $b))->assertCreated();
        $second = $this->postJson('/api/orders/flow', $this->payload($key, $a, $b))->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::query()->where('creation_key', $key)->count());
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->data()['query'] ?? '', 'orderCreate'))->count());

        Order::query()->where('creation_key', $key)->update(['flow_state' => 'creating', 'shopify_order_id' => null, 'shopify_shop_id' => null]);
        $this->http();
        $this->postJson('/api/orders/flow', $this->payload($key, $a, $b))->assertStatus(409)->assertJsonPath('message', 'Création déjà en cours…');
        Http::assertNothingSent();
    }

    public function test_webhook_before_and_after_the_mutation_keeps_one_order(): void
    {
        $admin = $this->signInAdmin();
        $shop = $this->shop();
        [$a, $b] = $this->variants();
        $key = (string) Str::uuid();
        $node = $this->node(9300, '#9300', 'PENDING', '275.00', '275.00', '20.00', '30.00');

        $this->http(function ($request) use ($shop, $key, $node) {
            if (str_contains($request->data()['query'] ?? '', 'orderCreate')) {
                $rest = app(ShopifyOrderNormalizer::class)->toRest($node);
                $rest['note_attributes'] = [['name' => 'lavfast_creation_key', 'value' => $key]];
                $rest['source_identifier'] = $key;
                app(OrderSyncService::class)->upsertFromShopifyPayload($shop, $rest, 'webhook');

                return Http::response(['data' => ['orderCreate' => ['userErrors' => [], 'order' => $node]]]);
            }

            return Http::response(['data' => ['orders' => ['nodes' => []]]]);
        });

        $created = $this->postJson('/api/orders/flow', $this->payload($key, $a, $b, ['internal_note' => 'Note interne']))->assertCreated();
        $this->assertSame(1, Order::query()->count());
        $order = Order::findOrFail($created->json('data.id'));
        $this->assertSame('Note interne', $order->internal_note);
        $this->assertSame($admin->id, $order->created_by);
        $this->assertSame('Lav’Fast Flow', $order->source);
        $this->assertSame('to_confirm', $order->confirmation_status);
        $this->assertTrue(ShopifySyncLog::query()->where('entity_id', $order->id)->where('status', 'echo')->exists());

        $key2 = (string) Str::uuid();
        $node2 = $this->node(9301, '#9301', 'PENDING', '275.00', '275.00', '20.00', '30.00');
        $this->http(fn () => Http::response(['data' => ['orderCreate' => ['userErrors' => [], 'order' => $node2]]]));
        $second = $this->postJson('/api/orders/flow', $this->payload($key2, $a, $b, ['internal_note' => 'Après']))->assertCreated();
        $rest = app(ShopifyOrderNormalizer::class)->toRest($node2);
        $rest['note_attributes'] = [['name' => 'lavfast_creation_key', 'value' => $key2]];
        $rest['source_identifier'] = $key2;
        app(OrderSyncService::class)->upsertFromShopifyPayload($shop, $rest, 'webhook');
        $this->assertSame(2, Order::query()->count());
        $again = Order::findOrFail($second->json('data.id'));
        $this->assertSame('Après', $again->internal_note);
        $this->assertSame($admin->id, $again->commercial_user_id);
        $this->assertSame(9301, (int) $again->shopify_order_id);
        $this->assertTrue(ShopifySyncLog::query()->where('entity_id', $again->id)->where('status', 'echo')->exists());
    }

    public function test_shopify_failure_keeps_a_draft_and_retry_reuses_the_key(): void
    {
        $this->signInAdmin();
        $this->shop();
        [$a, $b] = $this->variants();
        $key = (string) Str::uuid();
        $this->http(fn () => Http::response(['data' => ['orderCreate' => [
            'userErrors' => [['field' => ['lineItems'], 'message' => 'Variante inconnue']],
            'order' => null,
        ]]]));

        $this->postJson('/api/orders/flow', $this->payload($key, $a, $b))->assertStatus(422);
        $draft = Order::query()->where('creation_key', $key)->first();
        $this->assertNotNull($draft);
        $this->assertSame('draft', $draft->flow_state);
        $this->assertSame('failed', $draft->shopify_sync_status);
        $this->assertNull($draft->shopify_order_id);

        $this->http(fn () => Http::response(['data' => ['orderCreate' => [
            'userErrors' => [],
            'order' => $this->node(9400, '#9400', 'PENDING', '275.00', '275.00', '20.00', '30.00'),
        ]]]));
        $this->postJson("/api/orders/{$draft->id}/flow-retry")->assertCreated();
        $this->assertSame(1, Order::query()->where('creation_key', $key)->count());
        Http::assertSent(fn ($r) => ($this->gql($r)['variables']['order']['sourceIdentifier'] ?? null) === $key);
        $this->assertSame(9400, (int) $draft->fresh()->shopify_order_id);
    }

    public function test_rate_limit_then_success_creates_one_order(): void
    {
        $this->signInAdmin();
        $this->shop();
        [$a, $b] = $this->variants();
        $calls = 0;
        $this->http(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response('throttled', 429);
            }

            return Http::response(['data' => ['orderCreate' => [
                'userErrors' => [],
                'order' => $this->node(9401, '#9401', 'PENDING', '275.00', '275.00', '20.00', '30.00'),
            ]]]);
        });

        $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b))->assertCreated();
        $this->assertSame(1, Order::query()->whereNotNull('shopify_order_id')->count());
        $this->assertSame(2, $calls);
    }

    public function test_rejected_phone_is_retried_without_the_customer(): void
    {
        $this->signInAdmin();
        $this->shop();
        [$a, $b] = $this->variants();
        $calls = 0;
        $this->http(function ($request) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response(['data' => ['orderCreate' => [
                    'userErrors' => [['field' => ['order', 'customer', 'phone'], 'message' => 'Phone is invalid']],
                    'order' => null,
                ]]]);
            }
            $this->assertArrayNotHasKey('customer', $this->gql($request)['variables']['order']);

            return Http::response(['data' => ['orderCreate' => [
                'userErrors' => [],
                'order' => $this->node(9402, '#9402', 'PENDING', '275.00', '275.00', '20.00', '30.00'),
            ]]]);
        });

        $res = $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b))->assertCreated();
        $this->assertStringContainsString('téléphone', mb_strtolower(implode(' ', (array) $res->json('warnings'))));
        $this->assertNotNull($res->json('data.shopify_order_id'));
    }

    public function test_timeout_searches_shopify_before_creating_again(): void
    {
        $this->signInAdmin();
        $shop = $this->shop();
        [$a, $b] = $this->variants();
        $key = (string) Str::uuid();
        $creates = 0;
        $this->http(function ($request) use (&$creates, $key) {
            $query = $request->data()['query'] ?? '';
            if (str_contains($query, 'orderCreate')) {
                $creates++;
                throw new ConnectionException('timeout');
            }
            $node = $this->node(9403, '#9403', 'PENDING', '275.00', '275.00', '20.00', '30.00');
            $node['sourceIdentifier'] = $key;

            return Http::response(['data' => ['orders' => ['nodes' => [$node]]]]);
        });

        $this->postJson('/api/orders/flow', $this->payload($key, $a, $b))->assertCreated();
        $this->assertSame(1, $creates);
        $this->assertSame(9403, (int) Order::query()->where('creation_key', $key)->value('shopify_order_id'));
        $this->assertSame($shop->id, Order::query()->where('creation_key', $key)->value('shopify_shop_id'));
    }

    public function test_timeout_does_not_resend_order_create_when_the_search_misses(): void
    {
        $this->signInAdmin();
        $this->shop();
        [$a, $b] = $this->variants();
        $key = (string) Str::uuid();
        $creates = 0;
        $searches = [];
        $this->http(function ($request) use (&$creates, &$searches) {
            $body = $this->gql($request);
            if (str_contains($body['query'] ?? '', 'orderCreate')) {
                $creates++;
                throw new ConnectionException('timeout');
            }
            $searches[] = $body;

            return Http::response(['data' => ['orders' => ['nodes' => []]]]);
        });

        $this->postJson('/api/orders/flow', $this->payload($key, $a, $b))
            ->assertStatus(422)
            ->assertJsonPath('errors.shopify.0', 'Shopify n’a pas répondu : la commande a peut-être été créée. Réessayer vérifie d’abord Shopify.');
        $this->assertSame(1, $creates);
        $order = Order::query()->where('creation_key', $key)->first();
        $this->assertSame('failed', $order->shopify_sync_status);
        $this->assertSame('draft', $order->flow_state);
        $this->assertNull($order->shopify_order_id);
        $this->assertStringContainsString('reverse: true', $searches[0]['query']);
        $this->assertStringContainsString('CREATED_AT', $searches[0]['query']);
        $this->assertSame('tag:"'.$key.'"', $searches[0]['variables']['query']);

        $this->http(function ($request) use (&$creates, $key) {
            $body = $this->gql($request);
            if (str_contains($body['query'] ?? '', 'orderCreate')) {
                $creates++;

                return Http::response(['data' => ['orderCreate' => [
                    'userErrors' => [],
                    'order' => $this->node(9501, '#9501', 'PENDING', '275.00', '275.00', '20.00', '30.00'),
                ]]]);
            }
            $node = $this->node(9501, '#9501', 'PENDING', '275.00', '275.00', '20.00', '30.00');
            $node['sourceIdentifier'] = $key;

            return Http::response(['data' => ['orders' => ['nodes' => [$node]]]]);
        });
        $this->postJson("/api/orders/{$order->id}/flow-retry")->assertCreated();
        $this->assertSame(1, $creates);
        $fresh = $order->fresh();
        $this->assertSame(9501, (int) $fresh->shopify_order_id);
        $this->assertSame('created', $fresh->flow_state);
        $this->assertSame(1, Order::query()->where('creation_key', $key)->count());
    }

    public function test_missing_shopify_variant_fails_before_claim_and_a_stale_creating_row_can_resume(): void
    {
        $this->signInAdmin();
        $this->shop();
        [$a, $b] = $this->variants();
        $a->forceFill(['shopify_variant_id' => null])->save();
        $this->http();
        $missing = $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b))->assertStatus(422);
        $this->assertSame('Ce produit n’a pas d’identifiant Shopify.', $missing->json('errors')['lines.0'][0]);
        $this->assertSame(0, Order::query()->where('flow_state', 'creating')->count());
        Http::assertNothingSent();

        $a->forceFill(['shopify_variant_id' => 111])->save();
        $freshKey = (string) Str::uuid();
        $recent = Order::create([
            'company_id' => Company::default()->id,
            'customer_name' => 'Sara',
            'phone' => '0612345678',
            'total_price' => 10,
            'source' => 'Lav’Fast Flow',
            'flow_state' => 'creating',
            'shopify_sync_status' => 'pending',
            'creation_key' => $freshKey,
            'status' => 'pending',
            'confirmation_status' => 'to_confirm',
        ]);
        DB::table('orders')->where('id', $recent->id)->update(['updated_at' => now()->subSeconds(10)]);
        $this->postJson('/api/orders/flow', $this->payload($freshKey, $a, $b))->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame('creating', $recent->fresh()->flow_state);

        $staleKey = (string) Str::uuid();
        $stale = Order::create([
            'company_id' => Company::default()->id,
            'customer_name' => 'Sara',
            'phone' => '0612345678',
            'total_price' => 10,
            'source' => 'Lav’Fast Flow',
            'flow_state' => 'creating',
            'shopify_sync_status' => 'pending',
            'creation_key' => $staleKey,
            'status' => 'pending',
            'confirmation_status' => 'to_confirm',
        ]);
        DB::table('orders')->where('id', $stale->id)->update(['updated_at' => now()->subMinutes(3)]);
        $creates = 0;
        $this->http(function ($request) use (&$creates, $staleKey) {
            $body = $this->gql($request);
            if (str_contains($body['query'] ?? '', 'orderCreate')) {
                $creates++;

                return Http::response(['data' => ['orderCreate' => ['userErrors' => [], 'order' => null]]]);
            }
            $node = $this->node(9600, '#9600', 'PENDING', '275.00', '275.00', '20.00', '30.00');
            $node['sourceIdentifier'] = $staleKey;

            return Http::response(['data' => ['orders' => ['nodes' => [$node]]]]);
        });
        $this->postJson('/api/orders/flow', $this->payload($staleKey, $a, $b))->assertCreated();
        $this->assertSame(0, $creates);
        $this->assertSame('created', $stale->fresh()->flow_state);
        $this->assertSame(9600, (int) $stale->fresh()->shopify_order_id);
    }

    public function test_draft_repost_does_not_overwrite_a_created_order(): void
    {
        $this->signInAdmin();
        $this->shop();
        [$a, $b] = $this->variants();
        $key = (string) Str::uuid();
        $this->http(fn () => Http::response(['data' => ['orderCreate' => [
            'userErrors' => [],
            'order' => $this->node(9700, '#9700', 'PENDING', '275.00', '275.00', '20.00', '30.00'),
        ]]]));
        $created = $this->postJson('/api/orders/flow', $this->payload($key, $a, $b))->assertCreated();
        $before = Order::findOrFail($created->json('data.id'));

        $this->http();
        $this->postJson('/api/orders/flow', $this->payload($key, $a, $b, [
            'draft' => true,
            'customer_name' => 'Autre personne',
        ]))->assertOk();
        Http::assertNothingSent();
        $after = $before->fresh();
        $this->assertSame('created', $after->flow_state);
        $this->assertSame($before->customer_name, $after->customer_name);
        $this->assertSame(9700, (int) $after->shopify_order_id);
        $this->assertSame(1, Order::query()->where('creation_key', $key)->count());
    }

    public function test_percent_discount_is_sent_as_a_fixed_amount(): void
    {
        $this->signInAdmin();
        $this->shop();
        [$a, $b] = $this->variants();
        $sent = null;
        $this->http(function ($request) use (&$sent) {
            $body = $this->gql($request);
            if (str_contains($body['query'] ?? '', 'orderCreate')) {
                $sent = $body;
            }

            return Http::response(['data' => ['orderCreate' => [
                'userErrors' => [],
                'order' => $this->node(9800, '#9800', 'PENDING', '240.00', '240.00', '25.00', '0.00'),
            ]]]);
        });
        $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b, [
            'discount_kind' => 'percent',
            'discount_value' => 10,
            'shipping_price' => 0,
            'fees' => [['label' => 'Emballage', 'amount' => 15]],
        ]))->assertCreated();
        $order = $sent['variables']['order'];
        $this->assertSame('25.00', $order['discountCode']['itemFixedDiscountCode']['amountSet']['shopMoney']['amount']);
        $this->assertArrayNotHasKey('itemPercentageDiscountCode', $order['discountCode']);
        $this->assertSame('240.00', $order['transactions'][0]['amountSet']['shopMoney']['amount']);
        $this->assertSame('PENDING', $order['transactions'][0]['status']);
    }

    public function test_another_companys_creation_key_and_commercial_are_refused(): void
    {
        $admin = $this->signInAdmin();
        $admin->resolveCompanyId();
        $this->shop();
        [$a, $b] = $this->variants();
        $other = Company::create(['name' => 'Autre', 'slug' => 'autre-cle', 'is_active' => true]);
        $key = (string) Str::uuid();
        Order::create([
            'company_id' => $other->id,
            'customer_name' => 'Ailleurs',
            'phone' => '0655555555',
            'total_price' => 10,
            'source' => 'Lav’Fast Flow',
            'flow_state' => 'draft',
            'creation_key' => $key,
            'status' => 'pending',
            'confirmation_status' => 'to_confirm',
        ]);
        $this->http();
        $this->postJson('/api/orders/flow', $this->payload($key, $a, $b))
            ->assertStatus(422)
            ->assertJsonPath('errors.creation_key.0', 'Clé de création invalide.');
        Http::assertNothingSent();
        $this->assertSame($other->id, (int) Order::query()->where('creation_key', $key)->value('company_id'));

        $stranger = User::factory()->create([
            'role' => User::ROLE_USER,
            'company_id' => $other->id,
            'is_active' => true,
        ]);
        $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b, [
            'draft' => true,
            'commercial_user_id' => $stranger->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('commercial_user_id');
    }

    public function test_without_shop_or_capability_the_order_stays_in_flow(): void
    {
        $this->signInAdmin();
        [$a, $b] = $this->variants();
        $this->http();
        $res = $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b))->assertCreated();
        $this->assertSame('Boutique Shopify non connectée : commande créée uniquement dans Lav’Fast Flow', $res->json('notice'));
        $this->assertNull($res->json('data.shopify_order_id'));
        $this->assertSame('Lav’Fast Flow', $res->json('data.source'));
        Http::assertNothingSent();

        $this->shop(['granted_scopes' => 'read_orders', 'scopes' => 'read_orders']);
        $res = $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b))->assertCreated();
        $this->assertSame('Boutique Shopify non connectée : commande créée uniquement dans Lav’Fast Flow', $res->json('notice'));
        Http::assertNothingSent();
    }

    public function test_drafts_are_hidden_from_confirmation(): void
    {
        $this->signInAdmin();
        [$a, $b] = $this->variants();
        $this->http();
        $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b, ['draft' => true]))->assertCreated();
        $this->assertSame('draft', Order::query()->first()->flow_state);
        $this->getJson('/api/confirmation/orders')->assertOk()->assertJsonPath('meta.total', 0);
        Http::assertNothingSent();
    }

    public function test_permissions_and_visibility(): void
    {
        $this->shop();
        [$a, $b] = $this->variants();
        $agent = User::factory()->create(['role' => User::ROLE_USER, 'company_id' => Company::default()->id]);
        Setting::setValue('role_permissions', [
            'orders.create' => ['admin'],
            'orders.view_all' => ['admin'],
        ]);

        $this->actingAs($agent)->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b))->assertForbidden();

        Setting::setValue('role_permissions', ['orders.view_all' => ['admin']]);
        $this->actingAs($agent);
        $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b, [
            'lines' => [['variant_id' => $a->id, 'quantity' => 1, 'price' => 10]],
            'discount_value' => 0,
            'shipping_price' => 0,
            'fees' => [],
        ]))->assertForbidden();
        $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b, [
            'discount_value' => 5,
            'shipping_price' => 0,
            'fees' => [],
        ]))->assertForbidden();

        $this->http();
        $own = $this->postJson('/api/orders/flow', $this->payload((string) Str::uuid(), $a, $b, [
            'draft' => true,
            'discount_value' => 0,
            'shipping_price' => 0,
            'fees' => [],
        ]))->assertCreated();
        $other = Order::create([
            'customer_name' => 'Autre',
            'phone' => '0622222222',
            'total_price' => 10,
            'source' => 'Shopify',
            'created_by' => User::factory()->create(['role' => User::ROLE_USER])->id,
            'company_id' => Company::default()->id,
        ]);
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own->json('data.id'));
        $this->getJson('/api/orders/'.$other->id)->assertNotFound();

        $commercial = Order::create([
            'customer_name' => 'Portefeuille',
            'phone' => '0633333333',
            'total_price' => 10,
            'commercial_user_id' => $agent->id,
            'company_id' => Company::default()->id,
        ]);
        $assigned = Order::create([
            'customer_name' => 'Agent',
            'phone' => '0644444444',
            'total_price' => 10,
            'assigned_user_id' => $agent->id,
            'company_id' => Company::default()->id,
        ]);
        $ids = collect($this->getJson('/api/orders')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($commercial->id));
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($other->id));
    }

    private function shop(array $extra = []): ShopifyShop
    {
        return ShopifyShop::create($extra + [
            'company_id' => Company::default()->id,
            'shop_domain' => 'example-shop.myshopify.com',
            'shop_name' => 'Example',
            'access_token' => 'shpat_test',
            'scopes' => 'write_orders',
            'granted_scopes' => 'read_orders,write_orders',
            'currency' => 'MAD',
            'is_active' => true,
            'installed_at' => now(),
        ]);
    }

    /** @return array{0: ProductVariant, 1: ProductVariant} */
    private function variants(): array
    {
        $product = Product::create([
            'company_id' => Company::default()->id,
            'title' => 'Tapis',
            'status' => 'active',
            'source' => 'shopify',
            'shopify_product_id' => 500,
        ]);

        return [
            ProductVariant::create([
                'product_id' => $product->id,
                'company_id' => $product->company_id,
                'title' => '4D',
                'sku' => 'TAP-4D',
                'price' => 100,
                'shopify_variant_id' => 111,
                'inventory_quantity' => 8,
                'inventory_tracked' => true,
                'inventory_policy' => 'deny',
            ]),
            ProductVariant::create([
                'product_id' => $product->id,
                'company_id' => $product->company_id,
                'title' => '7D',
                'sku' => 'TAP-7D',
                'price' => 50,
                'shopify_variant_id' => 222,
                'inventory_quantity' => 4,
                'inventory_tracked' => true,
                'inventory_policy' => 'continue',
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function node(int $id, string $name, string $financial, string $total, string $outstanding, string $discount, string $shipping): array
    {
        return [
            'legacyResourceId' => (string) $id,
            'name' => $name,
            'email' => 'client@example.com',
            'phone' => '+212612345678',
            'note' => 'Sonner à l’étage',
            'tags' => ['lavfast-flow'],
            'displayFinancialStatus' => $financial,
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'currencyCode' => 'MAD',
            'totalPriceSet' => ['shopMoney' => ['amount' => $total]],
            'totalOutstandingSet' => ['shopMoney' => ['amount' => $outstanding]],
            'totalDiscountsSet' => ['shopMoney' => ['amount' => $discount]],
            'totalShippingPriceSet' => ['shopMoney' => ['amount' => $shipping]],
            'customer' => ['legacyResourceId' => '77', 'firstName' => 'Sara', 'lastName' => 'Bennani', 'phone' => '+212612345678'],
            'shippingAddress' => ['address1' => '12 rue des Orangeraies', 'city' => 'Casablanca', 'country' => 'Morocco', 'phone' => '+212612345678'],
            'lineItems' => ['nodes' => []],
            'customAttributes' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function gql(mixed $request): array
    {
        $data = json_decode(json_encode($request->data()), true);

        return is_array($data) ? $data : [];
    }

    private function http(?callable $callback = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake($callback ?? fn () => Http::response(['data' => []], 200));
    }

    /** @param  array<string, mixed>  $extra */
    private function payload(string $key, ProductVariant $a, ProductVariant $b, array $extra = []): array
    {
        return $extra + [
            'creation_key' => $key,
            'customer_name' => 'Sara Bennani',
            'customer_phone' => '0612345678',
            'email' => 'client@example.com',
            'city' => 'Casablanca',
            'address' => '12 rue des Orangeraies',
            'lines' => [
                ['variant_id' => $a->id, 'quantity' => 2],
                ['variant_id' => $b->id, 'quantity' => 1],
            ],
            'discount_kind' => 'amount',
            'discount_value' => 20,
            'shipping_price' => 30,
            'fees' => [['label' => 'Emballage', 'amount' => 15]],
            'payment_method' => 'cod',
            'note' => 'Sonner à l’étage',
        ];
    }
}
