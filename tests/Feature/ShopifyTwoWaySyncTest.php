<?php

namespace Tests\Feature;

use App\Jobs\ProcessShopifyWebhookJob;
use App\Jobs\ShopifyReconcileJob;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopifyAppSetting;
use App\Models\ShopifyFieldChange;
use App\Models\ShopifyShop;
use App\Models\ShopifySyncLog;
use App\Models\ShopifyWebhookEvent;
use App\Models\User;
use App\Services\Catalog\CatalogLookup;
use App\Services\Shopify\CatalogSyncService;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Brahim's mandatory cases (section A) plus the technical inbound cases.
 * Outbound cases (9, 10, 11, anti-loop push, order edit) are added with Part 2.
 */
class ShopifyTwoWaySyncTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        ShopifyAppSetting::current()->forceFill(['client_secret' => 'whsec'])->save();
    }

    /**
     * Produits 1. modifier titre Shopify → vérifier Flow.
     * « Tapis 4D Peugeot 208 » → « Tapis 7D Peugeot 208 ».
     */
    public function test_case_01_product_title_change_reaches_flow(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis 4D Peugeot 208'));
        $this->webhook($shop, 'products/update', $this->productPayload(11, 'Tapis 7D Peugeot 208', '2026-10-08T12:00:00Z'));

        $this->assertSame('Tapis 7D Peugeot 208', Product::where('shopify_product_id', 11)->value('title'));
    }

    /** Produits 2. modifier prix Shopify → vérifier Flow. */
    public function test_case_02_product_price_change_reaches_flow(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis', '2026-10-08T10:00:00Z', '300.00'));
        $this->webhook($shop, 'products/update', $this->productPayload(11, 'Tapis', '2026-10-08T12:00:00Z', '450.00'));

        $this->assertEquals(450, (float) ProductVariant::where('shopify_variant_id', 111)->value('price'));
    }

    /** Produits 3. changer photo Shopify → vérifier Flow. */
    public function test_case_03_product_photo_change_reaches_flow(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis'));
        $changed = $this->productPayload(11, 'Tapis', '2026-10-08T12:00:00Z');
        $changed['image'] = ['src' => 'https://cdn.example.com/new.jpg'];
        $changed['images'] = [['id' => 9, 'src' => 'https://cdn.example.com/new.jpg']];
        $this->webhook($shop, 'products/update', $changed);

        $this->assertSame('https://cdn.example.com/new.jpg', Product::where('shopify_product_id', 11)->value('image_url'));
        $this->assertSame(['https://cdn.example.com/new.jpg'], Product::where('shopify_product_id', 11)->first()->images);
    }

    /** Produits 4. ajouter une variante → vérifier Flow. */
    public function test_case_04_new_variant_reaches_flow(): void
    {
        $shop = $this->shop();
        $payload = $this->productPayload(11, 'Tapis');
        $payload['variants'][] = [
            'id' => 112, 'title' => '7D', 'sku' => 'TAP-7D', 'price' => '450.00', 'inventory_quantity' => 4,
            'inventory_management' => 'shopify', 'inventory_policy' => 'deny', 'inventory_item_id' => 1112, 'position' => 2,
        ];
        $payload['updated_at'] = '2026-10-08T12:00:00Z';
        $this->webhook($shop, 'products/update', $this->productPayload(11, 'Tapis'));
        $this->webhook($shop, 'products/update', $payload);

        $this->assertNotNull(ProductVariant::where('shopify_variant_id', 112)->first());
    }

    /** Produits 5. modifier stock → vérifier Flow. */
    public function test_case_05_stock_change_reaches_flow(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis'));
        $this->webhook($shop, 'inventory_levels/update', [
            'inventory_item_id' => 1111, 'location_id' => 7, 'available' => 3,
        ]);

        $this->assertSame(3, ProductVariant::where('shopify_variant_id', 111)->value('inventory_quantity'));
    }

    /** Commandes 6. nouvelle commande Shopify → Flow. */
    public function test_case_06_new_shopify_order_reaches_flow(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'orders/create', $this->orderPayload());

        $order = Order::where('shopify_order_id', 9001)->first();
        $this->assertNotNull($order);
        $this->assertSame('Sara Bennani', $order->customer_name);
        $this->assertEquals(0, $order->amountDue());
        $this->assertSame($shop->company_id, $order->company_id);
    }

    /** Commandes 7. correction téléphone Shopify → Flow. */
    public function test_case_07_phone_change_reaches_flow(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'orders/create', $this->orderPayload());
        $next = $this->orderPayload();
        $next['phone'] = '0661000000';
        $next['updated_at'] = '2026-10-08T13:00:00Z';
        $this->webhook($shop, 'orders/updated', $next);

        $order = Order::where('shopify_order_id', 9001)->first();
        $this->assertSame('0661000000', $order->phone);
        $this->assertTrue(OrderStatusHistory::where('order_id', $order->id)->where('kind', 'shopify')->where('note', 'like', 'Shopify : téléphone%')->exists());
    }

    /** Commandes 8. changement adresse Shopify → Flow. */
    public function test_case_08_address_change_reaches_flow(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'orders/create', $this->orderPayload());
        $next = $this->orderPayload();
        $next['shipping_address']['address1'] = '99 boulevard d’Anfa';
        $next['shipping_address']['city'] = 'Rabat';
        $next['updated_at'] = '2026-10-08T13:00:00Z';
        $this->webhook($shop, 'orders/updated', $next);

        $order = Order::where('shopify_order_id', 9001)->first();
        $this->assertSame('Rabat', $order->shipping_address['city']);
        $this->assertTrue(OrderStatusHistory::where('order_id', $order->id)->where('note', 'Shopify : adresse modifiée')->exists());
    }

    /** Tracking 12. modifier tracking Shopify → Flow. */
    public function test_case_12_shopify_tracking_change_reaches_flow(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'orders/create', $this->orderPayload());
        $this->webhook($shop, 'fulfillments/create', [
            'id' => 55, 'order_id' => 9001, 'status' => 'success',
            'tracking_number' => 'OZE1', 'tracking_company' => 'Ozon', 'tracking_url' => 'https://track.example/OZE1',
        ]);
        $this->webhook($shop, 'fulfillments/update', [
            'id' => 55, 'order_id' => 9001, 'status' => 'success',
            'tracking_number' => 'OZE12345', 'tracking_company' => 'Ozon', 'tracking_url' => 'https://track.example/OZE12345',
            'updated_at' => '2026-10-08T14:00:00Z',
        ]);

        $row = OrderFulfillment::where('shopify_fulfillment_id', 55)->first();
        $this->assertSame('OZE12345', $row->tracking_number);
        $this->assertTrue(OrderStatusHistory::where('note', 'like', '%OZE12345%')->exists());
    }

    /** Erreurs 13. couper l’API puis vérifier le retry. */
    public function test_case_13_api_outage_retries_then_succeeds(): void
    {
        $shop = $this->shop();
        Http::fake([
            'example-shop.myshopify.com/*' => Http::sequence()
                ->push('', 429, ['Retry-After' => '12'])
                ->push(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [$this->graphqlOrder()]]]], 200),
        ]);

        $job = new class($shop->id) extends ShopifyReconcileJob
        {
            public ?int $released = null;

            public function release($delay = 0): void
            {
                $this->released = (int) $delay;
            }
        };
        $job->handle(app(\App\Services\Shopify\ShopifyReconcileService::class), app(\App\Services\Shopify\ShopifySyncLogger::class));
        $this->assertSame(12, $job->released);
        $this->assertNull(Order::where('shopify_order_id', 9001)->first());

        $job->handle(app(\App\Services\Shopify\ShopifyReconcileService::class), app(\App\Services\Shopify\ShopifySyncLogger::class));
        $this->assertNotNull(Order::where('shopify_order_id', 9001)->first());
    }

    /** Erreurs 14. renvoyer deux fois le même webhook → aucun doublon. */
    public function test_case_14_duplicate_webhook_is_processed_once(): void
    {
        $shop = $this->shop();
        $payload = $this->orderPayload();
        $this->webhook($shop, 'orders/create', $payload, 'wh-1');
        $this->webhook($shop, 'orders/create', $payload, 'wh-1')->assertOk();

        $this->assertSame(1, Order::where('shopify_order_id', 9001)->count());
        $this->assertSame(1, ShopifyWebhookEvent::count());
    }

    public function test_invalid_hmac_is_rejected_and_stores_nothing(): void
    {
        $shop = $this->shop();
        $raw = json_encode($this->orderPayload());
        $this->call('POST', '/shopify/webhooks', [], [], [], [
            'HTTP_X-Shopify-Hmac-Sha256' => 'nope',
            'HTTP_X-Shopify-Topic' => 'orders/create',
            'HTTP_X-Shopify-Shop-Domain' => $shop->shop_domain,
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertStatus(401);

        $this->assertSame(0, ShopifyWebhookEvent::count());
        $this->assertSame(0, Order::where('shopify_order_id', 9001)->count());
    }

    public function test_valid_hmac_stores_the_event_and_queues_the_shopify_job(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->webhook($shop, 'orders/create', $this->orderPayload(), 'wh-queue')->assertOk();

        $this->assertSame(1, ShopifyWebhookEvent::where('webhook_id', 'wh-queue')->count());
        Queue::assertPushedOn('shopify', ProcessShopifyWebhookJob::class);
    }

    public function test_older_payload_is_ignored(): void
    {
        $shop = $this->shop();
        $newer = $this->orderPayload();
        $newer['phone'] = '0661000000';
        $newer['updated_at'] = '2026-10-08T15:00:00Z';
        $this->webhook($shop, 'orders/updated', $newer, 'wh-new');

        $older = $this->orderPayload();
        $older['phone'] = '0600000000';
        $older['updated_at'] = '2026-10-08T09:00:00Z';
        $this->webhook($shop, 'orders/updated', $older, 'wh-old');

        $this->assertSame('0661000000', Order::where('shopify_order_id', 9001)->value('phone'));
        $this->assertSame('ignored', ShopifyWebhookEvent::where('webhook_id', 'wh-old')->value('status'));
    }

    public function test_graphql_user_errors_are_not_retryable_and_429_is(): void
    {
        $shop = $this->shop();
        Http::fake([
            'example-shop.myshopify.com/*' => Http::sequence()
                ->push('', 503, ['Retry-After' => '4'])
                ->push(['data' => ['orderEditCommit' => ['userErrors' => [['message' => 'Déjà clôturée']]]]], 200),
        ]);
        $client = new ShopifyClient($shop->shop_domain, 'shpat_test', '2026-10');
        try {
            $client->get('shop.json');
            $this->fail('expected exception');
        } catch (ShopifyApiException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(4, $e->retryAfter);
        }

        try {
            $client->graphqlMutation('mutation { orderEditCommit { userErrors { message } } }', [], 'orderEditCommit');
            $this->fail('expected userErrors');
        } catch (ShopifyApiException $e) {
            $this->assertFalse($e->retryable, $e->getMessage());
            $this->assertSame(422, $e->status);
            $this->assertSame('Déjà clôturée', $e->userErrors[0]['message']);
        }
    }

    public function test_two_shops_with_the_same_shopify_ids_stay_isolated(): void
    {
        $a = $this->shop();
        $bCompany = Company::create(['name' => 'Autre', 'slug' => 'autre', 'is_active' => true]);
        $b = $this->shop([
            'company_id' => $bCompany->id,
            'shop_domain' => 'other-shop.myshopify.com',
            'shop_name' => 'Autre',
        ]);

        $this->webhook($a, 'products/create', $this->productPayload(11, 'Produit A'));
        $this->webhook($b, 'products/create', $this->productPayload(11, 'Produit B'));
        $this->webhook($a, 'orders/create', $this->orderPayload());
        $orderB = $this->orderPayload();
        $orderB['email'] = 'b@example.com';
        $this->webhook($b, 'orders/create', $orderB, 'wh-b');

        $this->assertSame('Produit A', Product::where('shopify_shop_id', $a->id)->where('shopify_product_id', 11)->value('title'));
        $this->assertSame('Produit B', Product::where('shopify_shop_id', $b->id)->where('shopify_product_id', 11)->value('title'));
        $this->assertSame('client@example.com', Order::where('shopify_shop_id', $a->id)->value('email'));
        $this->assertSame('b@example.com', Order::where('shopify_shop_id', $b->id)->value('email'));

        $userA = User::factory()->create(['role' => User::ROLE_ADMIN, 'company_id' => $a->company_id]);
        $userB = User::factory()->create(['role' => User::ROLE_ADMIN, 'company_id' => $bCompany->id]);
        $this->actingAs($userA)->getJson('/api/integrations/shopify/logs')->assertOk()
            ->assertJsonMissing(['shopify_shop_id' => $b->id]);
        $visible = $this->actingAs($userB)->getJson('/api/integrations/shopify/logs')->json('data');
        $this->assertNotEmpty($visible);
        foreach ($visible as $row) {
            $this->assertSame($bCompany->id, $row['company_id']);
        }
    }

    public function test_product_images_follow_shopify_and_variant_priority(): void
    {
        $shop = $this->shop();
        $payload = $this->productPayload(11, 'Tapis');
        $payload['images'] = [
            ['id' => 1, 'src' => 'https://cdn.example.com/main.jpg'],
            ['id' => 2, 'src' => 'https://cdn.example.com/extra.jpg'],
        ];
        $payload['image'] = ['src' => 'https://cdn.example.com/main.jpg'];
        $payload['variants'][0]['image_id'] = 2;
        $this->webhook($shop, 'products/create', $payload);

        $product = Product::where('shopify_product_id', 11)->first();
        $this->assertEqualsCanonicalizing(
            ['https://cdn.example.com/main.jpg', 'https://cdn.example.com/extra.jpg'],
            $product->images
        );
        $variant = $product->variants()->first();
        $this->assertSame('https://cdn.example.com/extra.jpg', $variant->imageUrl());

        $removed = $payload;
        $removed['updated_at'] = '2026-10-08T16:00:00Z';
        $removed['images'] = [['id' => 1, 'src' => 'https://cdn.example.com/main.jpg']];
        $removed['variants'][0]['image_id'] = null;
        $this->webhook($shop, 'products/update', $removed);
        $product->refresh();
        $this->assertSame(['https://cdn.example.com/main.jpg'], $product->images);
        $this->assertSame('https://cdn.example.com/main.jpg', $product->variants()->first()->imageUrl());

        $bare = ProductVariant::create([
            'product_id' => $product->id, 'company_id' => $product->company_id, 'title' => 'Sans photo', 'price' => 1,
        ]);
        $product->forceFill(['image_url' => null])->save();
        $bare->setRelation('product', $product->fresh());
        $this->assertNull($bare->imageUrl());
        $this->assertNull(app(CatalogLookup::class)->variantFor(['variant_id' => null, 'sku' => null]));
    }

    /** A discounted sold price stays when the catalog price changes later. */
    public function test_sold_price_is_independent_from_later_catalog_price(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis', '2026-10-08T10:00:00Z', '300.00'));
        $order = $this->orderPayload();
        $order['line_items'][0]['price'] = '250.00';
        $order['line_items'][0]['catalog_price'] = '300.00';
        $this->webhook($shop, 'orders/create', $order);

        $this->webhook($shop, 'products/update', $this->productPayload(11, 'Tapis', '2026-10-08T18:00:00Z', '450.00'));

        $line = Order::where('shopify_order_id', 9001)->first()->line_items[0];
        $this->assertSame('250.00', (string) $line['price']);
        $this->assertEquals(450, (float) ProductVariant::where('shopify_variant_id', 111)->value('price'));
    }

    public function test_reconcile_picks_up_a_missed_order_and_dry_run_writes_nothing(): void
    {
        $shop = $this->shop();
        Http::fake([
            'example-shop.myshopify.com/*' => Http::response([
                'data' => ['orders' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [$this->graphqlOrder()]]],
            ], 200),
        ]);

        $this->artisan('shopify:reconcile', ['--shop' => $shop->shop_domain, '--dry-run' => true, '--since' => '2026-10-01'])->assertOk();
        $this->assertNull(Order::where('shopify_order_id', 9001)->first());
        $this->assertNull($shop->fresh()->orders_reconciled_at);

        $this->artisan('shopify:reconcile', ['--shop' => $shop->shop_domain, '--since' => '2026-10-01'])->assertOk();
        $this->assertNotNull(Order::where('shopify_order_id', 9001)->first());
    }

    public function test_legacy_internal_edit_is_kept_and_flagged_as_conflict(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'orders/create', $this->orderPayload());
        $order = Order::where('shopify_order_id', 9001)->first();
        $order->forceFill([
            'items_edited_at' => now(),
            'line_items' => [['id' => 1, 'title' => 'Modifié dans Flow', 'quantity' => 2, 'price' => '10.00']],
            'total_price' => 20,
        ])->save();

        $next = $this->orderPayload();
        $next['updated_at'] = '2026-10-08T19:00:00Z';
        $next['line_items'][0]['title'] = 'Autre produit Shopify';
        $this->webhook($shop, 'orders/updated', $next, 'wh-conflict');

        $order->refresh();
        $this->assertSame('Modifié dans Flow', $order->line_items[0]['title']);
        $this->assertSame('conflict', $order->shopify_sync_status);
        $this->assertTrue(ShopifyFieldChange::where('entity_id', $order->id)->where('conflict', true)->exists()
            || OrderStatusHistory::where('order_id', $order->id)->where('note', 'like', '%conflit%')->exists());
    }

    public function test_customer_update_refreshes_the_shops_orders(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'orders/create', $this->orderPayload());
        $this->webhook($shop, 'customers/update', [
            'id' => 42, 'first_name' => 'Sara', 'last_name' => 'Alami', 'email' => 'sara@example.com', 'phone' => '0677000000',
        ]);

        $order = Order::where('shopify_order_id', 9001)->first();
        $this->assertSame('Sara Alami', $order->customer_name);
        $this->assertSame('sara@example.com', $order->email);
        $this->assertSame('0677000000', $order->phone);
    }

    /** Produits 9. modifier dans Flow → vérifier Shopify. */
    public function test_case_09_flow_title_is_pushed_to_shopify(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis 4D Peugeot 208'));
        $product = Product::where('shopify_product_id', 11)->first();
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[] = ($request->data()['query'] ?? '').json_encode($request->data()['variables'] ?? []);

            return Http::response(['data' => ['productUpdate' => ['product' => ['id' => 'gid://shopify/Product/11'], 'userErrors' => []]]], 200);
        });

        $this->putJson("/api/products/{$product->id}", ['title' => 'Tapis 7D Peugeot 208'])->assertOk();

        $this->assertSame('Tapis 7D Peugeot 208', $product->fresh()->title);
        $this->assertTrue(collect($sent)->contains(fn ($body) => str_contains($body, 'productUpdate') && str_contains($body, 'Tapis 7D Peugeot 208')));
    }

    public function test_product_push_user_error_does_not_change_the_local_title(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis 4D Peugeot 208'));
        $product = Product::where('shopify_product_id', 11)->first();
        Http::fake([
            'example-shop.myshopify.com/*' => Http::response(['data' => ['productUpdate' => ['userErrors' => [['message' => 'Titre refusé']]]]], 200),
        ]);

        $this->putJson("/api/products/{$product->id}", ['title' => 'Tapis 7D Peugeot 208'])->assertStatus(422);
        $this->assertSame('Tapis 4D Peugeot 208', $product->fresh()->title);
        $this->assertSame('failed', $product->fresh()->shopify_sync_status);
    }

    public function test_stock_is_pushed_only_when_inventory_write_is_enabled(): void
    {
        $admin = $this->signInAdmin();
        $shop = $this->writableShop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis'));
        $variant = ProductVariant::where('shopify_variant_id', 111)->first();

        Http::fake();
        $this->putJson("/api/products/variants/{$variant->id}", ['inventory_quantity' => 3])->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame(8, $variant->fresh()->inventory_quantity);

        Company::default()->forceFill(['features' => ['shopify_push_stock' => true]])->save();
        $shop->unsetRelation('company');
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request->data()['query'] ?? '';

            return Http::response(['data' => ['inventorySetQuantities' => ['userErrors' => []]]], 200);
        });
        $this->actingAs($admin)->putJson("/api/products/variants/{$variant->id}", ['inventory_quantity' => 3])->assertOk();
        $this->assertSame(3, $variant->fresh()->inventory_quantity);
        $this->assertTrue(collect($sent)->contains(fn ($q) => str_contains($q, 'inventorySetQuantities')));
    }

    public function test_flow_does_not_create_a_shopify_product(): void
    {
        $this->signInAdmin();
        $product = Product::create([
            'company_id' => Company::default()->id,
            'title' => 'Manuel',
            'status' => 'active',
        ]);
        Http::fake();
        $this->putJson("/api/products/{$product->id}", ['title' => 'Nouveau'])->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame('Manuel', $product->fresh()->title);
    }

    /** Commandes 10. remplacer un produit (FAST13050). */
    public function test_case_10_replace_product_is_committed_on_shopify_before_local_update(): void
    {
        $admin = $this->signInAdmin();
        $shop = $this->writableShop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis Peugeot 208'));
        $payload = $this->productPayload(12, 'Tapis 7D Peugeot 208', '2026-10-08T12:00:00Z', '450.00');
        $payload['variants'][0]['id'] = 222;
        $payload['variants'][0]['inventory_item_id'] = 2222;
        $this->webhook($shop, 'products/create', $payload);
        $variant = ProductVariant::where('shopify_variant_id', 222)->first();
        $order = Order::create([
            'company_id' => $shop->company_id,
            'shopify_shop_id' => $shop->id,
            'shopify_order_id' => 13050,
            'customer_name' => 'Client',
            'total_price' => 300,
            'currency' => 'MAD',
            'line_items' => [[
                'id' => 1, 'title' => 'Tapis Peugeot 208', 'quantity' => 1, 'price' => '300.00',
                'variant_id' => 111, 'product_id' => 11, 'sku' => 'TAP-208',
            ]],
        ]);
        $commit = $this->graphqlOrder();
        $commit['legacyResourceId'] = '13050';
        $commit['name'] = '#13050';
        $commit['totalPriceSet']['shopMoney']['amount'] = '450.00';
        $commit['totalOutstandingSet']['shopMoney']['amount'] = '150.00';
        $commit['displayFinancialStatus'] = 'PARTIALLY_PAID';
        $commit['lineItems']['nodes'][0]['title'] = 'Tapis 7D Peugeot 208';
        $commit['lineItems']['nodes'][0]['discountedUnitPriceSet']['shopMoney']['amount'] = '450.00';
        $commit['lineItems']['nodes'][0]['variant']['legacyResourceId'] = '222';

        $seen = [];
        Http::fake(function ($request) use (&$seen, $commit, $admin) {
            $query = (string) ($request->data()['query'] ?? '');
            $vars = json_encode($request->data()['variables'] ?? []);
            foreach (['orderEditBegin', 'orderEditAddVariant', 'orderEditSetQuantity', 'orderEditCommit'] as $name) {
                if (str_contains($query, 'mutation '.$name) || str_contains($query, $name.'(')) {
                    $seen[] = $name;
                }
            }
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
            if (str_contains($query, 'orderEditCommit')) {
                $decoded = json_decode($vars, true);
                $this->assertSame('Modifié depuis Lav’Fast Flow par '.$admin->name, $decoded['staffNote'] ?? null);

                return Http::response(['data' => ['orderEditCommit' => ['order' => $commit, 'userErrors' => []]]], 200);
            }

            return Http::response(['data' => ['orderEditAddVariant' => ['userErrors' => []], 'orderEditSetQuantity' => ['userErrors' => []]]], 200);
        });

        $this->postJson("/api/orders/{$order->id}/items/s1/replace", [
            'variant_id' => $variant->id, 'quantity' => 1,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(['orderEditBegin', 'orderEditAddVariant', 'orderEditSetQuantity', 'orderEditCommit'], $seen);
        $this->assertSame('Tapis 7D Peugeot 208', $order->line_items[0]['title']);
        $this->assertEquals(450, (float) $order->total_price);
        $this->assertEquals(150, $order->amountDue());
    }

    public function test_order_edit_without_scope_is_refused_and_sends_nothing(): void
    {
        $this->signInAdmin();
        $shop = $this->shop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis'));
        $order = Order::create([
            'company_id' => $shop->company_id,
            'shopify_shop_id' => $shop->id,
            'shopify_order_id' => 55,
            'customer_name' => 'Client',
            'total_price' => 300,
            'line_items' => [['id' => 1, 'title' => 'Tapis', 'quantity' => 1, 'price' => '300.00', 'variant_id' => 111]],
        ]);
        Http::fake();
        $this->postJson("/api/orders/{$order->id}/items/s1/replace", [
            'variant_id' => ProductVariant::where('shopify_variant_id', 111)->value('id'),
            'quantity' => 1,
        ])->assertStatus(422)->assertJsonPath('errors.shopify.0', 'Modification Shopify non autorisée — reconnecter Shopify');
        Http::assertNothingSent();
        $this->assertSame('Tapis', $order->fresh()->line_items[0]['title']);
    }

    public function test_price_increase_on_an_existing_line_is_blocked(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = Order::create([
            'company_id' => $shop->company_id,
            'shopify_shop_id' => $shop->id,
            'shopify_order_id' => 56,
            'customer_name' => 'Client',
            'total_price' => 300,
            'line_items' => [['id' => 1, 'title' => 'Tapis', 'quantity' => 1, 'price' => '300.00', 'variant_id' => 111]],
        ]);
        Http::fake();
        $this->putJson("/api/orders/{$order->id}/items/s1", ['price' => 450])
            ->assertStatus(422)
            ->assertJsonPath('errors.price.0', \App\Services\Shopify\ShopifyOrderEditService::PRICE_INCREASE);
        Http::assertNothingSent();
        $this->assertEquals(300, (float) $order->fresh()->line_items[0]['price']);
    }

    public function test_user_errors_fail_immediately_and_retry_requeues_the_same_job(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $this->webhook($shop, 'products/create', $this->productPayload(11, 'Tapis'));
        $order = Order::create([
            'company_id' => $shop->company_id,
            'shopify_shop_id' => $shop->id,
            'shopify_order_id' => 57,
            'customer_name' => 'Client',
            'total_price' => 300,
            'line_items' => [['id' => 1, 'title' => 'Tapis', 'quantity' => 1, 'price' => '300.00', 'variant_id' => 111]],
        ]);
        Queue::fake();
        Http::fake([
            'example-shop.myshopify.com/*' => Http::response([
                'data' => ['orderEditBegin' => ['userErrors' => [['message' => 'Déjà clôturée']]]],
            ], 200),
        ]);
        $variantId = ProductVariant::where('shopify_variant_id', 111)->value('id');
        $this->postJson("/api/orders/{$order->id}/items/s1/replace", ['variant_id' => $variantId, 'quantity' => 1])
            ->assertStatus(422);
        $this->assertSame('Tapis', $order->fresh()->line_items[0]['title']);
        $log = ShopifySyncLog::where('action', 'order_edit')->where('status', 'failed')->first();
        $this->assertNotNull($log);
        Queue::assertNothingPushed();

        $this->postJson("/api/integrations/shopify/logs/{$log->id}/retry")->assertOk();
        $this->assertSame('pending', $log->fresh()->status);
        Queue::assertPushed(\App\Jobs\RetryShopifyOutboundJob::class);
    }

    /** Commandes 11. tracking Flow → Shopify, without calling a carrier. */
    public function test_case_11_tracking_is_pushed_to_shopify(): void
    {
        $this->signInAdmin();
        $shop = $this->writableShop();
        $order = Order::create([
            'company_id' => $shop->company_id,
            'shopify_shop_id' => $shop->id,
            'shopify_order_id' => 58,
            'customer_name' => 'Client',
            'total_price' => 300,
            'line_items' => [],
        ]);
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $query = (string) ($request->data()['query'] ?? '');
            $sent[] = $query.json_encode($request->data()['variables'] ?? []);
            if (str_contains($query, 'fulfillmentOrders')) {
                return Http::response(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [[
                    'id' => 'gid://shopify/FulfillmentOrder/5',
                    'status' => 'OPEN',
                    'supportedActions' => [['action' => 'CREATE_FULFILLMENT']],
                ]]]]]], 200);
            }

            return Http::response(['data' => ['fulfillmentCreate' => [
                'fulfillment' => [
                    'id' => 'gid://shopify/Fulfillment/8',
                    'status' => 'SUCCESS',
                    'trackingInfo' => [['number' => 'OZE12345', 'company' => 'Ozon']],
                ],
                'userErrors' => [],
            ]]], 200);
        });

        $this->postJson("/api/orders/{$order->id}/shopify-tracking", [
            'tracking_number' => 'OZE12345',
            'tracking_company' => 'Ozon',
            'notify_customer' => false,
        ])->assertOk();

        $this->assertDatabaseHas('order_fulfillments', ['order_id' => $order->id, 'tracking_number' => 'OZE12345', 'source' => 'flow']);
        $this->assertTrue(collect($sent)->contains(fn ($body) => str_contains($body, 'fulfillmentCreate') && str_contains($body, 'OZE12345')));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ozon') || str_contains($request->url(), 'speedaf') || str_contains($request->url(), 'sift'));
    }

    public function test_outbound_echo_does_not_fire_the_automation_again(): void
    {
        $admin = $this->signInAdmin();
        $shop = $this->writableShop();
        \App\Models\Automation::create([
            'company_id' => $shop->company_id,
            'name' => 'Note sur mise à jour',
            'status' => \App\Models\Automation::STATUS_ACTIVE,
            'trigger_type' => 'shopify.order_updated',
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
        $this->webhook($shop, 'orders/create', $this->orderPayload());
        $this->assertSame(1, \App\Models\AutomationRun::count());

        Http::fake([
            'example-shop.myshopify.com/*' => Http::response(['data' => ['orderUpdate' => ['order' => ['id' => 'gid://shopify/Order/9001'], 'userErrors' => []]]], 200),
        ]);
        $order = Order::where('shopify_order_id', 9001)->first();
        $this->postJson("/api/orders/{$order->id}/shopify-customer", ['phone' => '0699000000'])->assertOk();
        $this->assertSame('0699000000', $order->fresh()->phone);

        $next = $this->orderPayload();
        $next['phone'] = '0699000000';
        $next['updated_at'] = '2026-10-08T18:00:00Z';
        $this->webhook($shop, 'orders/updated', $next, 'wh-echo');
        $this->assertSame(1, \App\Models\AutomationRun::count());
        $this->assertSame('echo', ShopifySyncLog::where('shopify_id', '9001')->latest('id')->value('status'));
    }

    public function test_fulfillment_simulation_never_calls_shopify(): void
    {
        Http::fake();
        $result = (new \App\Services\Automations\Actions\ShopifyCreateFulfillmentAction)->handle(
            ['tracking_number' => '{{vars.trackingNumber}}'],
            ['order' => ['id' => 1]],
            true,
        );
        $this->assertTrue($result['simulated']);
        Http::assertNothingSent();
    }

    protected function writableShop(): ShopifyShop
    {
        return $this->shop([
            'scopes' => mb_substr('write_orders,write_order_edits,write_products', 0, 255),
            'granted_scopes' => 'read_orders,write_orders,write_order_edits,read_products,write_products,read_inventory,write_inventory,read_merchant_managed_fulfillment_orders,write_merchant_managed_fulfillment_orders',
            'inventory_location_id' => '7',
        ]);
    }

    protected function shop(array $extra = []): ShopifyShop
    {
        return ShopifyShop::create($extra + [
            'company_id' => Company::default()->id,
            'shop_domain' => 'example-shop.myshopify.com',
            'shop_name' => 'Example',
            'access_token' => 'shpat_test',
            'scopes' => 'read_orders,read_products,read_inventory',
            'granted_scopes' => 'read_orders,read_products,read_inventory',
            'is_active' => true,
            'installed_at' => now(),
        ]);
    }

    protected function webhook(ShopifyShop $shop, string $topic, array $payload, ?string $id = null)
    {
        $raw = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $raw, 'whsec', true));
        $headers = [
            'HTTP_X-Shopify-Hmac-Sha256' => $hmac,
            'HTTP_X-Shopify-Topic' => $topic,
            'HTTP_X-Shopify-Shop-Domain' => $shop->shop_domain,
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($id) {
            $headers['HTTP_X-Shopify-Webhook-Id'] = $id;
        }

        return $this->call('POST', '/shopify/webhooks', [], [], [], $headers, $raw);
    }

    protected function productPayload(int $id, string $title, string $updated = '2026-10-08T11:00:00Z', string $price = '300.00'): array
    {
        return [
            'id' => $id, 'title' => $title, 'status' => 'active', 'updated_at' => $updated,
            'body_html' => '<p>Description utile</p>',
            'options' => [['name' => 'Finition', 'values' => ['4D', '7D']]],
            'image' => ['src' => 'https://cdn.example.com/p.jpg'],
            'images' => [['id' => 1, 'src' => 'https://cdn.example.com/p.jpg']],
            'variants' => [[
                'id' => 111, 'title' => 'Standard', 'sku' => 'TAP-208', 'price' => $price, 'barcode' => '123',
                'compare_at_price' => '500.00', 'inventory_quantity' => 8, 'inventory_management' => 'shopify',
                'inventory_policy' => 'deny', 'inventory_item_id' => 1111, 'position' => 1,
            ]],
        ];
    }

    protected function orderPayload(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/shopify/order-paid.json')), true);
    }

    /** @return array<string, mixed> */
    private function graphqlOrder(): array
    {
        return [
            'legacyResourceId' => '9001',
            'name' => '#9001',
            'email' => 'client@example.com',
            'phone' => '0612000000',
            'note' => null,
            'tags' => ['vip'],
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'cancelledAt' => null,
            'createdAt' => '2026-10-08T10:00:00Z',
            'updatedAt' => '2026-10-08T12:00:00Z',
            'currencyCode' => 'MAD',
            'paymentGatewayNames' => ['shopify_payments'],
            'totalPriceSet' => ['shopMoney' => ['amount' => '450.00']],
            'totalOutstandingSet' => ['shopMoney' => ['amount' => '0.00']],
            'totalDiscountsSet' => ['shopMoney' => ['amount' => '0.00']],
            'totalShippingPriceSet' => ['shopMoney' => ['amount' => '0.00']],
            'customer' => ['legacyResourceId' => '42', 'firstName' => 'Sara', 'lastName' => 'Bennani', 'email' => 'client@example.com', 'phone' => '0612000000'],
            'shippingAddress' => ['firstName' => 'Sara', 'lastName' => 'Bennani', 'address1' => '12 rue', 'city' => 'Casablanca', 'phone' => '0612000000'],
            'lineItems' => ['nodes' => [[
                'id' => 'gid://shopify/LineItem/1', 'title' => 'Tapis', 'quantity' => 1, 'sku' => 'TAP',
                'discountedUnitPriceSet' => ['shopMoney' => ['amount' => '450.00']],
                'originalUnitPriceSet' => ['shopMoney' => ['amount' => '450.00']],
                'variant' => ['legacyResourceId' => '111', 'price' => '450.00'],
                'product' => ['legacyResourceId' => '11'],
            ]]],
            'fulfillments' => [],
            'refunds' => [],
        ];
    }
}
