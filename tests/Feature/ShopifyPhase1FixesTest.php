<?php

namespace Tests\Feature;

use App\Jobs\ProcessShopifyWebhookJob;
use App\Jobs\RetryShopifyOutboundJob;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderFulfillment;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ShopifySyncLog;
use App\Models\ShopifyWebhookEvent;
use App\Models\User;
use App\Services\Automations\VariableResolver;
use App\Services\Payments\AmountDue;
use App\Services\Shopify\SyncContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ShopifyTwoWaySyncTest as SyncHarness;

class ShopifyPhase1FixesTest extends SyncHarness
{

    public function test_shopify_discount_changes_amount_due_only_after_commit(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 77, 300);
        $amounts = [];
        Http::fake(function ($request) use (&$amounts) {
            $body = json_decode(json_encode($request->data()), true) ?: [];
            $query = (string) ($body['query'] ?? '');
            $vars = $body['variables'] ?? [];
            if (str_contains($query, 'orderEditBegin')) {
                return Http::response(['data' => ['orderEditBegin' => ['calculatedOrder' => [
                    'id' => 'gid://shopify/CalculatedOrder/1',
                    'lineItems' => ['nodes' => [[
                        'id' => 'gid://shopify/CalculatedLineItem/1',
                        'quantity' => 1,
                        'variant' => ['legacyResourceId' => '111'],
                    ]]],
                ], 'userErrors' => []]]], 200);
            }
            if (str_contains($query, 'orderEditAddLineItemDiscount')) {
                $amounts[] = $vars['discount']['fixedValue']['amount'] ?? $vars['discount']['percentValue'] ?? null;

                return Http::response(['data' => ['orderEditAddLineItemDiscount' => [
                    'calculatedOrder' => ['addedDiscountApplications' => ['nodes' => [['id' => 'gid://shopify/CalculatedDiscountApplication/9']]]],
                    'userErrors' => [],
                ]]], 200);
            }
            if (str_contains($query, 'orderEditCommit')) {
                return Http::response(['data' => ['orderEditCommit' => ['order' => $this->commitNode('77', '250.00'), 'userErrors' => []]]], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $this->postJson("/api/confirmation/orders/{$order->id}/discounts", [
            'type' => 'amount', 'value' => 50, 'reason' => 'Geste',
        ])->assertCreated();

        $order->refresh();
        $this->assertEquals(250, (float) $order->total_price);
        $this->assertEquals(250, $order->amountDue());
        $this->assertNull($order->items_edited_at);
        $this->assertSame(['50.00'], $amounts);
        $discount = OrderDiscount::where('order_id', $order->id)->first();
        $this->assertSame(['gid://shopify/CalculatedDiscountApplication/9'], $discount->shopify_discount_ids['ids']);
        $this->assertStringStartsWith('Remise Lav’Fast Flow · ', $discount->shopify_discount_ids['tag']);
        $this->assertSame('pushed', OrderStatusHistory::where('order_id', $order->id)->where('kind', 'remise')->value('data')['shopify'] ?? null);
    }

    public function test_amount_discount_is_split_so_the_shares_sum_exactly(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 78, 300);
        $order->forceFill(['line_items' => [
            ['id' => 1, 'title' => 'A', 'quantity' => 1, 'price' => '200.00', 'variant_id' => 111],
            ['id' => 2, 'title' => 'B', 'quantity' => 1, 'price' => '100.00', 'variant_id' => 222],
        ]])->save();
        $amounts = [];
        Http::fake(function ($request) use (&$amounts) {
            $body = json_decode(json_encode($request->data()), true) ?: [];
            $query = (string) ($body['query'] ?? '');
            $vars = $body['variables'] ?? [];
            if (str_contains($query, 'orderEditBegin')) {
                return Http::response(['data' => ['orderEditBegin' => ['calculatedOrder' => [
                    'id' => 'gid://shopify/CalculatedOrder/1',
                    'lineItems' => ['nodes' => [
                        ['id' => 'gid://shopify/CalculatedLineItem/1', 'variant' => ['legacyResourceId' => '111']],
                        ['id' => 'gid://shopify/CalculatedLineItem/2', 'variant' => ['legacyResourceId' => '222']],
                    ]],
                ], 'userErrors' => []]]], 200);
            }
            if (str_contains($query, 'orderEditAddLineItemDiscount')) {
                $amounts[] = $vars['discount']['fixedValue']['amount'] ?? null;

                return Http::response(['data' => ['orderEditAddLineItemDiscount' => [
                    'calculatedOrder' => ['addedDiscountApplications' => ['nodes' => [['id' => 'gid://shopify/CalculatedDiscountApplication/'.count($amounts)]]]],
                    'userErrors' => [],
                ]]], 200);
            }
            if (str_contains($query, 'orderEditCommit')) {
                return Http::response(['data' => ['orderEditCommit' => ['order' => $this->commitNode('78', '250.00'), 'userErrors' => []]]], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $this->postJson("/api/confirmation/orders/{$order->id}/discounts", ['type' => 'amount', 'value' => 50])->assertCreated();
        $this->assertEqualsWithDelta(50, array_sum(array_map('floatval', $amounts)), 0.001);
        $this->assertCount(2, $amounts);
    }

    public function test_shopify_discount_without_order_edit_changes_nothing(): void
    {
        $this->signInAdmin();
        $shop = $this->shop();
        $order = $this->connectedOrder($shop, 79, 300);
        Http::fake();

        $this->postJson("/api/confirmation/orders/{$order->id}/discounts", ['type' => 'amount', 'value' => 50])
            ->assertStatus(422)
            ->assertJsonPath('errors.shopify.0', 'Modification Shopify non autorisée — reconnecter Shopify');

        Http::assertNothingSent();
        $this->assertEquals(300, (float) $order->fresh()->total_price);
        $this->assertSame(0, OrderDiscount::where('order_id', $order->id)->count());
    }

    public function test_manual_discount_stays_local(): void
    {
        $this->signInAdmin();
        $order = Order::create([
            'company_id' => Company::default()->id,
            'customer_name' => 'Client',
            'total_price' => 300,
            'currency' => 'MAD',
            'financial_status' => 'pending',
            'line_items' => [['id' => 1, 'title' => 'Tapis', 'quantity' => 1, 'price' => '300.00']],
        ]);

        $this->postJson("/api/confirmation/orders/{$order->id}/discounts", ['type' => 'amount', 'value' => 50])->assertCreated();
        $this->assertEquals(250, (float) $order->fresh()->total_price);
        $this->assertNotNull($order->fresh()->items_edited_at);
    }

    public function test_legacy_edited_shopify_order_collects_the_local_total(): void
    {
        $order = Order::create([
            'company_id' => Company::default()->id,
            'shopify_order_id' => 80,
            'customer_name' => 'Client',
            'total_price' => 250,
            'total_outstanding' => 300,
            'amount_paid' => 0,
            'financial_status' => 'pending',
            'items_edited_at' => now(),
        ]);

        $this->assertEquals(250, app(AmountDue::class)->calculate($order));
    }

    public function test_order_form_pushes_phone_through_shopify(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 81, 300);
        Http::fake([
            'example-shop.myshopify.com/*' => Http::response(['data' => ['orderUpdate' => ['order' => ['id' => 'gid://shopify/Order/81'], 'userErrors' => []]]], 200),
        ]);

        $this->putJson("/api/orders/{$order->id}", [
            'customer_phone' => '0699000000',
            'amount' => 300,
        ])->assertOk();

        $this->assertSame('0699000000', $order->fresh()->phone);
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'orderUpdate'));
    }

    public function test_order_form_refuses_customer_edit_without_permission_or_scope(): void
    {
        $shop = $this->shop();
        $order = $this->connectedOrder($shop, 82, 300);
        $agent = User::factory()->create(['role' => User::ROLE_USER, 'company_id' => $shop->company_id]);
        Http::fake();

        $this->actingAs($agent)->putJson("/api/orders/{$order->id}", [
            'customer_phone' => '0699000000',
            'amount' => 300,
        ])->assertForbidden();
        $this->assertSame('0611111111', $order->fresh()->phone);
        Http::assertNothingSent();

        $this->signInAdmin();
        $this->putJson("/api/orders/{$order->id}", [
            'customer_phone' => '0699000000',
            'amount' => 300,
        ])->assertStatus(422)->assertJsonPath('errors.shopify.0', 'Modification Shopify non autorisée — reconnecter Shopify');
        $this->assertSame('0611111111', $order->fresh()->phone);
        Http::assertNothingSent();
    }

    public function test_shopify_order_actions_are_company_scoped(): void
    {
        $this->signInAdmin();
        $other = Company::create(['name' => 'Autre', 'slug' => 'autre-societe', 'is_active' => true]);
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 83, 300);
        $order->forceFill(['company_id' => $other->id])->save();
        Http::fake();

        $this->postJson("/api/orders/{$order->id}/shopify-customer", ['phone' => '0600000000'])->assertNotFound();
        $this->postJson("/api/orders/{$order->id}/shopify-retry")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_orders_edited_fetches_the_order_and_fires_the_trigger(): void
    {
        $admin = $this->signInAdmin();
        $shop = $this->writableShop();
        $this->automation($admin, $shop->company_id, 'shopify.order_edited');
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/order_edited.json')), true);
        Http::fake([
            'example-shop.myshopify.com/*' => Http::response(['data' => ['order' => $this->commitNode('9001', '450.00', [
                'lineItems' => ['nodes' => [[
                    'id' => 'gid://shopify/LineItem/2',
                    'title' => 'Tapis 7D',
                    'quantity' => 1,
                    'discountedUnitPriceSet' => ['shopMoney' => ['amount' => '450.00']],
                    'variant' => ['legacyResourceId' => '222', 'price' => '450.00'],
                    'product' => ['legacyResourceId' => '12'],
                ]]],
            ])]], 200),
        ]);

        $this->webhook($shop, 'orders/edited', $payload, 'wh-edited')->assertOk();

        $order = Order::where('shopify_order_id', 9001)->first();
        $this->assertNotNull($order);
        $this->assertSame('Tapis 7D', $order->line_items[0]['title']);
        $this->assertSame('9001', ShopifyWebhookEvent::where('topic', 'orders/edited')->value('resource_id'));
        $this->assertSame(1, AutomationRun::whereHas('automation', fn ($q) => $q->where('trigger_type', 'shopify.order_edited'))->count());
        $this->assertSame('9001', ProcessShopifyWebhookJob::resourceId($payload));
        $this->assertSame('55', ProcessShopifyWebhookJob::resourceId(['order_id' => 55, 'id' => 9]));
    }

    public function test_echo_is_consumed_and_a_later_payment_is_not_an_echo_of_a_line_edit(): void
    {
        $admin = $this->signInAdmin();
        $shop = $this->writableShop();
        $this->automation($admin, $shop->company_id, 'shopify.order_updated');
        $this->webhook($shop, 'orders/create', $this->orderPayload(), 'wh-create');
        $runs = AutomationRun::count();

        Http::fake([
            'example-shop.myshopify.com/*' => Http::response(['data' => ['orderUpdate' => ['order' => ['id' => 'gid://shopify/Order/9001'], 'userErrors' => []]]], 200),
        ]);
        $order = Order::where('shopify_order_id', 9001)->first();
        $this->postJson("/api/orders/{$order->id}/shopify-customer", ['phone' => '0699000000'])->assertOk();

        $echo = $this->orderPayload();
        $echo['phone'] = '0699000000';
        $echo['updated_at'] = '2026-10-08T18:00:00Z';
        $this->webhook($shop, 'orders/updated', $echo, 'wh-echo-1');
        $this->assertSame($runs, AutomationRun::count());
        $this->assertSame('echo', ShopifySyncLog::where('shopify_id', '9001')->latest('id')->value('status'));

        $echo['updated_at'] = '2026-10-08T18:05:00Z';
        $this->webhook($shop, 'orders/updated', $echo, 'wh-echo-2');
        $this->assertSame($runs + 1, AutomationRun::count());
        $this->assertSame('success', ShopifySyncLog::where('shopify_id', '9001')->latest('id')->value('status'));
    }

    public function test_payment_after_a_line_edit_is_not_an_echo(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 84, 300);
        Http::fake(function ($request) {
            $body = json_decode(json_encode($request->data()), true) ?: [];
            $query = (string) ($body['query'] ?? '');
            if (str_contains($query, 'orderEditBegin')) {
                return Http::response(['data' => ['orderEditBegin' => ['calculatedOrder' => [
                    'id' => 'gid://shopify/CalculatedOrder/2',
                    'lineItems' => ['nodes' => [['id' => 'gid://shopify/CalculatedLineItem/1', 'variant' => ['legacyResourceId' => '111']]]],
                ], 'userErrors' => []]]], 200);
            }
            if (str_contains($query, 'orderEditCommit')) {
                return Http::response(['data' => ['orderEditCommit' => ['order' => $this->commitNode('84', '250.00'), 'userErrors' => []]]], 200);
            }

            return Http::response(['data' => ['orderEditAddLineItemDiscount' => [
                'calculatedOrder' => ['addedDiscountApplications' => ['nodes' => [['id' => 'gid://shopify/CalculatedDiscountApplication/1']]]],
                'userErrors' => [],
            ]]], 200);
        });
        $this->postJson("/api/confirmation/orders/{$order->id}/discounts", ['type' => 'amount', 'value' => 50])->assertCreated();
        $paid = [
            'id' => 84,
            'name' => '#84',
            'phone' => '0611111111',
            'email' => 'a@example.com',
            'financial_status' => 'paid',
            'total_price' => '250.00',
            'total_outstanding' => '0.00',
            'updated_at' => '2026-10-08T19:00:00Z',
            'currency' => 'MAD',
            'shipping_address' => ['first_name' => 'A', 'last_name' => 'B', 'address1' => '1 rue', 'address2' => null, 'city' => 'Rabat', 'province' => null, 'zip' => null, 'country' => null, 'phone' => '0611111111'],
            'line_items' => [[
                'id' => 1, 'title' => 'Tapis', 'quantity' => 1, 'price' => '250.00', 'variant_id' => 111,
            ]],
        ];
        $this->webhook($shop, 'orders/updated', $paid, 'wh-paid');
        $this->assertSame('success', ShopifySyncLog::where('shopify_id', '84')->latest('id')->value('status'));
        $this->assertSame('paid', Order::where('shopify_order_id', 84)->value('financial_status'));
    }

    public function test_product_push_echo_is_consumed_once(): void
    {
        $admin = $this->signInAdmin();
        $shop = $this->writableShop();
        $this->automation($admin, $shop->company_id, 'shopify.product_updated');
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis'), 'wh-p-create');
        $before = AutomationRun::count();
        $product = Product::where('shopify_product_id', 11)->first();
        Http::fake([
            'example-shop.myshopify.com/*' => Http::response(['data' => ['productUpdate' => ['product' => ['id' => 'gid://shopify/Product/11'], 'userErrors' => []]]], 200),
        ]);
        $this->putJson("/api/products/{$product->id}", ['title' => 'Tapis neuf'])->assertOk();

        $updated = $this->productPayload(11, 'Tapis neuf', '2026-10-08T13:00:00Z');
        $this->webhook($shop, 'products/update', $updated, 'wh-p-echo');
        $this->assertSame($before, AutomationRun::count());
        $this->assertSame('echo', ShopifySyncLog::where('entity_type', 'product')->where('direction', 'in')->latest('id')->value('status'));

        $again = $this->productPayload(11, 'Tapis neuf', '2026-10-08T13:05:00Z');
        $this->webhook($shop, 'products/update', $again, 'wh-p-real');
        $this->assertSame($before + 1, AutomationRun::count());
    }

    public function test_suppress_is_cleared_after_the_observer(): void
    {
        $order = Order::create([
            'company_id' => Company::default()->id,
            'customer_name' => 'Client',
            'total_price' => 10,
        ]);
        SyncContext::suppressOrder($order->id, ['phone']);
        $order->forceFill(['note' => 'après'])->save();
        $this->assertNull(SyncContext::suppressedFor($order->id));
    }

    public function test_fulfillment_skips_a_closed_first_order(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 85, 300);
        $used = null;
        Http::fake(function ($request) use (&$used) {
            $body = json_decode(json_encode($request->data()), true) ?: [];
            $query = (string) ($body['query'] ?? '');
            if (str_contains($query, 'fulfillmentOrders')) {
                return Http::response(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [
                    ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'CLOSED', 'supportedActions' => []],
                    ['id' => 'gid://shopify/FulfillmentOrder/2', 'status' => 'OPEN', 'supportedActions' => [['action' => 'CREATE_FULFILLMENT']]],
                ]]]]], 200);
            }
            $used = $body['variables']['fulfillment']['lineItemsByFulfillmentOrder'] ?? [];

            return Http::response(['data' => ['fulfillmentCreate' => [
                'fulfillment' => ['id' => 'gid://shopify/Fulfillment/3', 'status' => 'SUCCESS', 'trackingInfo' => [['number' => 'TRK1']]],
                'userErrors' => [],
            ]]], 200);
        });

        $this->postJson("/api/orders/{$order->id}/shopify-tracking", [
            'tracking_number' => 'TRK1', 'tracking_company' => 'Ozon', 'notify_customer' => false,
        ])->assertOk();

        $this->assertSame([['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/2']], $used);
        $this->assertSame(1, OrderFulfillment::where('order_id', $order->id)->count());
    }

    public function test_fulfillment_with_no_open_order_is_refused(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 86, 300);
        Http::fake([
            'example-shop.myshopify.com/*' => Http::response(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [
                ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'CLOSED', 'supportedActions' => []],
            ]]]]], 200),
        ]);

        $this->postJson("/api/orders/{$order->id}/shopify-tracking", [
            'tracking_number' => 'TRK2', 'notify_customer' => false,
        ])->assertStatus(422)->assertJsonPath('errors.shopify.0', 'Aucune ligne à expédier sur Shopify pour cette commande');
        $this->assertSame(0, OrderFulfillment::where('order_id', $order->id)->count());
    }

    public function test_retry_endpoints_requeue_the_failed_outbound_log(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 87, 300);
        $order->forceFill(['shopify_sync_status' => 'failed', 'shopify_sync_error' => 'timeout'])->save();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis'), 'wh-retry-p');
        $product = Product::where('shopify_product_id', 11)->first();
        $product->forceFill(['shopify_sync_status' => 'failed'])->save();
        $plog = ShopifySyncLog::create([
            'company_id' => $product->company_id,
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'action' => 'product_push',
            'status' => 'failed',
            'request_excerpt' => json_encode(['op' => 'product']),
        ]);
        $log = ShopifySyncLog::create([
            'company_id' => $order->company_id,
            'shopify_shop_id' => $shop->id,
            'direction' => 'out',
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'shopify_id' => '87',
            'action' => 'order_edit',
            'source' => 'flow_user',
            'status' => 'failed',
            'error' => 'timeout',
            'request_excerpt' => json_encode(['op' => 'customer']),
        ]);
        Queue::fake();

        $this->postJson("/api/orders/{$order->id}/shopify-retry")->assertOk();
        $this->assertSame('pending', $log->fresh()->status);
        $this->assertSame('pending', $order->fresh()->shopify_sync_status);
        Queue::assertPushed(RetryShopifyOutboundJob::class);

        $this->postJson("/api/products/{$product->id}/shopify-retry")->assertOk();
        $this->assertSame('pending', $plog->fresh()->status);
        Queue::assertPushed(RetryShopifyOutboundJob::class);
    }

    public function test_amount_due_is_an_automation_variable(): void
    {
        $order = Order::create([
            'company_id' => Company::default()->id,
            'customer_name' => 'Client',
            'total_price' => 300,
            'amount_paid' => 50,
            'financial_status' => 'partially_paid',
        ]);
        $text = app(VariableResolver::class)->interpolate(
            'À encaisser {{order.amount_due}}',
            app(VariableResolver::class)->subjectContext($order),
        );
        $this->assertSame('À encaisser '.(string) $order->amountDue(), $text);
    }

    public function test_non_shopify_exception_marks_the_webhook_failed_and_rethrows(): void
    {
        $shop = $this->shop();
        $event = ShopifyWebhookEvent::create([
            'company_id' => $shop->company_id,
            'shopify_shop_id' => $shop->id,
            'topic' => 'orders/updated',
            'webhook_id' => 'wh-bad',
            'payload' => ['id' => 1],
            'status' => 'received',
        ]);
        $job = new ProcessShopifyWebhookJob('orders/updated', $shop->shop_domain, [
            'id' => 1,
            'line_items' => 'nope',
            'updated_at' => '2026-10-08T12:00:00Z',
        ], $event->id);

        try {
            app()->call([$job, 'handle']);
            $this->fail('The bad payload should throw.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->error);
    }

    public function test_legacy_local_edit_is_refused_before_any_shopify_call(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 91, 250);
        $order->forceFill([
            'items_edited_at' => now(),
            'shopify_sync_status' => 'conflict',
            'line_items' => [['id' => 1, 'title' => 'Tapis local', 'quantity' => 1, 'price' => '250.00', 'variant_id' => 111]],
        ])->save();
        Http::fake();

        $this->postJson("/api/confirmation/orders/{$order->id}/discounts", ['type' => 'amount', 'value' => 50])
            ->assertStatus(422)
            ->assertJsonPath('errors.shopify.0', \App\Services\Shopify\ShopifyOrderEditService::LEGACY_CONFLICT);
        $this->putJson("/api/orders/{$order->id}/items/s1", ['quantity' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.shopify.0', \App\Services\Shopify\ShopifyOrderEditService::LEGACY_CONFLICT);

        Http::assertNothingSent();
        $this->assertEquals(250, (float) $order->fresh()->total_price);
        $this->assertSame('Tapis local', $order->fresh()->line_items[0]['title']);
    }

    public function test_take_remote_replaces_local_lines_with_the_shopify_order(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 92, 250);
        $order->forceFill([
            'items_edited_at' => now(),
            'items_edited_by' => 1,
            'shopify_sync_status' => 'conflict',
            'total_outstanding' => 300,
            'line_items' => [['id' => 1, 'title' => 'Tapis local', 'quantity' => 1, 'price' => '250.00', 'variant_id' => 111]],
            'shopify_line_items' => [['id' => 1, 'title' => 'Tapis', 'quantity' => 1, 'price' => '300.00', 'variant_id' => 111]],
        ])->save();
        OrderStatusHistory::create([
            'order_id' => $order->id, 'kind' => 'produits', 'status_code' => 'items_edit', 'status_name' => 'Modifié',
            'note' => 'Ancienne modification locale',
        ]);
        Http::fake([
            'example-shop.myshopify.com/*' => Http::response(['data' => ['order' => $this->commitNode('92', '300.00', [
                'totalOutstandingSet' => ['shopMoney' => ['amount' => '300.00']],
                'lineItems' => ['nodes' => [[
                    'id' => 'gid://shopify/LineItem/1',
                    'title' => 'Tapis Shopify',
                    'quantity' => 1,
                    'discountedUnitPriceSet' => ['shopMoney' => ['amount' => '300.00']],
                    'variant' => ['legacyResourceId' => '111', 'price' => '300.00'],
                    'product' => ['legacyResourceId' => '11'],
                ]]],
            ])]], 200),
        ]);

        $this->postJson("/api/orders/{$order->id}/shopify-take-remote")->assertOk();

        $order->refresh();
        $this->assertNull($order->items_edited_at);
        $this->assertNull($order->items_edited_by);
        $this->assertSame('synced', $order->shopify_sync_status);
        $this->assertSame('Tapis Shopify', $order->line_items[0]['title']);
        $this->assertEquals(300, (float) $order->total_price);
        $this->assertEquals(300, $order->amountDue());
        $note = OrderStatusHistory::where('order_id', $order->id)->where('kind', 'shopify')->latest('id')->value('note');
        $this->assertStringStartsWith('Version Shopify reprise — modifications locales remplacées', $note);
        $this->assertStringContainsString('Tapis local ×1 → Tapis Shopify ×1', $note);
        $this->assertTrue(OrderStatusHistory::where('order_id', $order->id)->where('note', 'Ancienne modification locale')->exists());
    }

    public function test_removing_a_discount_uses_the_new_session_id_that_matches_the_tag(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 93, 250);
        $tag = 'Remise Lav’Fast Flow · 11111111-1111-1111-1111-111111111111';
        $discount = OrderDiscount::create([
            'order_id' => $order->id,
            'type' => 'amount',
            'value' => 50,
            'amount' => 50,
            'reason' => 'Geste',
            'shopify_discount_ids' => [
                'tag' => $tag,
                'ids' => ['gid://shopify/CalculatedDiscountApplication/OLD'],
            ],
        ]);
        $removed = [];
        Http::fake(function ($request) use (&$removed, $tag) {
            $body = json_decode(json_encode($request->data()), true) ?: [];
            $query = (string) ($body['query'] ?? '');
            if (str_contains($query, 'orderEditBegin')) {
                return Http::response(['data' => ['orderEditBegin' => ['calculatedOrder' => [
                    'id' => 'gid://shopify/CalculatedOrder/9',
                    'lineItems' => ['nodes' => [[
                        'id' => 'gid://shopify/CalculatedLineItem/1',
                        'variant' => ['legacyResourceId' => '111'],
                        'calculatedDiscountAllocations' => [[
                            'discountApplication' => [
                                'id' => 'gid://shopify/CalculatedDiscountApplication/NEW',
                                'description' => 'Geste · '.$tag,
                            ],
                        ]],
                    ]]],
                ], 'userErrors' => []]]], 200);
            }
            if (str_contains($query, 'orderEditRemoveDiscount')) {
                $removed[] = $body['variables']['discountApplicationId'] ?? null;

                return Http::response(['data' => ['orderEditRemoveDiscount' => ['calculatedOrder' => ['id' => 'gid://shopify/CalculatedOrder/9'], 'userErrors' => []]]], 200);
            }
            if (str_contains($query, 'orderEditCommit')) {
                return Http::response(['data' => ['orderEditCommit' => ['order' => $this->commitNode('93', '300.00'), 'userErrors' => []]]], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $this->deleteJson("/api/confirmation/orders/{$order->id}/discounts/{$discount->id}")->assertOk();

        $this->assertSame(['gid://shopify/CalculatedDiscountApplication/NEW'], $removed);
        $this->assertEquals(300, (float) $order->fresh()->total_price);
        $this->assertNotNull($discount->fresh()->removed_at);
    }

    public function test_discount_removal_without_a_match_changes_nothing(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = $this->connectedOrder($shop, 94, 250);
        $discount = OrderDiscount::create([
            'order_id' => $order->id,
            'type' => 'amount',
            'value' => 50,
            'amount' => 50,
            'shopify_discount_ids' => ['tag' => 'Remise Lav’Fast Flow · absent', 'ids' => []],
        ]);
        Http::fake(function ($request) {
            $body = json_decode(json_encode($request->data()), true) ?: [];
            $query = (string) ($body['query'] ?? '');
            if (str_contains($query, 'orderEditBegin')) {
                return Http::response(['data' => ['orderEditBegin' => ['calculatedOrder' => [
                    'id' => 'gid://shopify/CalculatedOrder/9',
                    'lineItems' => ['nodes' => [[
                        'id' => 'gid://shopify/CalculatedLineItem/1',
                        'calculatedDiscountAllocations' => [],
                    ]]],
                ], 'userErrors' => []]]], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $this->deleteJson("/api/confirmation/orders/{$order->id}/discounts/{$discount->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.shopify.0', \App\Services\Shopify\ShopifyOrderEditService::DISCOUNT_MISSING);
        $this->assertEquals(250, (float) $order->fresh()->total_price);
        $this->assertNull($discount->fresh()->removed_at);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->body(), 'orderEditCommit') || str_contains((string) $request->body(), 'orderEditRemoveDiscount'));
    }

    private function connectedOrder($shop, int $shopifyId, float $total): Order
    {
        return Order::create([
            'company_id' => $shop->company_id,
            'shopify_shop_id' => $shop->id,
            'shopify_order_id' => $shopifyId,
            'customer_name' => 'Client',
            'phone' => '0611111111',
            'email' => 'a@example.com',
            'total_price' => $total,
            'total_outstanding' => $total,
            'amount_paid' => 0,
            'currency' => 'MAD',
            'financial_status' => 'pending',
            'shipping_address' => ['address1' => '1 rue', 'city' => 'Rabat', 'phone' => '0611111111'],
            'line_items' => [[
                'id' => 1, 'title' => 'Tapis', 'quantity' => 1, 'price' => number_format($total, 2, '.', ''),
                'variant_id' => 111, 'product_id' => 11,
            ]],
        ]);
    }

    /** @param  array<string, mixed>  $over */
    private function commitNode(string $id, string $total, array $over = []): array
    {
        $node = [
            'legacyResourceId' => $id,
            'name' => '#'.$id,
            'email' => 'a@example.com',
            'phone' => '0611111111',
            'note' => null,
            'tags' => [],
            'displayFinancialStatus' => 'PENDING',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'cancelledAt' => null,
            'createdAt' => '2026-10-08T10:00:00Z',
            'updatedAt' => '2026-10-08T12:00:00Z',
            'currencyCode' => 'MAD',
            'paymentGatewayNames' => ['cash_on_delivery'],
            'totalPriceSet' => ['shopMoney' => ['amount' => $total]],
            'totalOutstandingSet' => ['shopMoney' => ['amount' => $total]],
            'totalDiscountsSet' => ['shopMoney' => ['amount' => '0.00']],
            'totalShippingPriceSet' => ['shopMoney' => ['amount' => '0.00']],
            'customer' => ['legacyResourceId' => '1', 'firstName' => 'A', 'lastName' => 'B', 'email' => 'a@example.com', 'phone' => '0611111111'],
            'shippingAddress' => ['firstName' => 'A', 'lastName' => 'B', 'address1' => '1 rue', 'city' => 'Rabat', 'phone' => '0611111111'],
            'lineItems' => ['nodes' => [[
                'id' => 'gid://shopify/LineItem/1',
                'title' => 'Tapis',
                'quantity' => 1,
                'sku' => 'TAP',
                'discountedUnitPriceSet' => ['shopMoney' => ['amount' => $total]],
                'originalUnitPriceSet' => ['shopMoney' => ['amount' => '300.00']],
                'variant' => ['legacyResourceId' => '111', 'price' => '300.00'],
                'product' => ['legacyResourceId' => '11'],
            ]]],
            'fulfillments' => [],
            'refunds' => [],
        ];

        return array_replace_recursive($node, $over);
    }

    private function automation(User $admin, int $companyId, string $trigger): void
    {
        Automation::create([
            'company_id' => $companyId,
            'name' => $trigger,
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => $trigger,
            'trigger_config' => [],
            'definition' => [
                'entry' => 'note',
                'steps' => [
                    'note' => [
                        'type' => 'action',
                        'action' => 'internal.add_note',
                        'config' => ['note' => 'depuis shopify', 'field' => 'internal_note'],
                        'next' => null,
                    ],
                ],
            ],
            'version' => 1,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }
}
