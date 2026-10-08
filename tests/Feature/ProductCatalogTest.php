<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShopifyAppSetting;
use App\Models\ShopifyShop;
use App\Models\User;
use App\Services\Shopify\CatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** T4 — Shopify catalog sync (GraphQL + webhooks), Produits API, internal order line editing. */
class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function shop(array $extra = []): ShopifyShop
    {
        return ShopifyShop::create($extra + [
            'company_id' => Company::default()->id,
            'shop_domain' => 'lavfast-dev.myshopify.com',
            'shop_name' => 'Lavfast Dev',
            'access_token' => 'shpat_test',
            'scopes' => 'read_orders,read_customers,read_products,read_inventory',
            'is_active' => true,
            'installed_at' => now(),
        ]);
    }

    protected function gqlProduct(int $id, string $title, array $variants, array $extra = []): array
    {
        return $extra + [
            'legacyResourceId' => (string) $id, 'title' => $title, 'handle' => strtolower($title), 'status' => 'ACTIVE',
            'vendor' => 'Lav', 'productType' => 'Auto', 'tags' => ['auto'], 'updatedAt' => '2026-10-01T10:00:00Z',
            'featuredImage' => ['url' => "https://cdn.shopify.com/p{$id}.jpg"],
            'images' => ['nodes' => [['url' => "https://cdn.shopify.com/p{$id}.jpg"]]],
            'collections' => ['nodes' => [['title' => 'Tapis']]],
            'variants' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => $variants],
        ];
    }

    protected function gqlVariant(int $id, string $title, string $sku, string $price, ?int $qty, ?string $image = null): array
    {
        return [
            'legacyResourceId' => (string) $id, 'title' => $title, 'sku' => $sku, 'barcode' => null, 'price' => $price,
            'compareAtPrice' => null, 'inventoryQuantity' => $qty, 'inventoryPolicy' => 'DENY', 'position' => 1,
            'image' => $image ? ['url' => $image] : null,
            'inventoryItem' => ['legacyResourceId' => (string) ($id + 1000), 'tracked' => $qty !== null],
        ];
    }

    protected function fakeCatalog(array $pages): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        $sequence = Http::sequence();
        foreach ($pages as $page) {
            $sequence->push(['data' => ['products' => $page]]);
        }
        Http::fake(['lavfast-dev.myshopify.com/admin/api/*/graphql.json' => $sequence]);
    }

    protected function seedCatalog(): ShopifyShop
    {
        $shop = $this->shop();
        $this->fakeCatalog([[
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [
                $this->gqlProduct(11, 'Tapis 3D', [
                    $this->gqlVariant(111, 'Clio 4', 'TAP-CLIO4', '300.00', 78, 'https://cdn.shopify.com/v111.jpg'),
                    $this->gqlVariant(112, 'Golf 7', 'TAP-GOLF7', '320.00', 0),
                ]),
                $this->gqlProduct(12, 'Housse volant', [$this->gqlVariant(121, 'Default Title', 'HOU-01', '150.00', null)], ['collections' => ['nodes' => [['title' => 'Volants']]]]),
            ],
        ]]);
        app(CatalogSyncService::class)->sync($shop, true);

        return $shop;
    }

    public function test_graphql_sync_creates_products_and_variants_without_duplicates(): void
    {
        $shop = $this->seedCatalog();

        $this->assertSame(2, Product::count());
        $this->assertSame(3, ProductVariant::count());
        $tapis = Product::where('shopify_product_id', 11)->firstOrFail();
        $this->assertSame('active', $tapis->status);
        $this->assertSame(['Tapis'], $tapis->collections);
        $this->assertSame($shop->company_id, $tapis->company_id);
        $clio = ProductVariant::where('shopify_variant_id', 111)->firstOrFail();
        $this->assertSame('TAP-CLIO4', $clio->sku);
        $this->assertSame(78, $clio->inventory_quantity);
        $this->assertSame('https://cdn.shopify.com/v111.jpg', $clio->imageUrl()); // variant image first
        $this->assertSame('https://cdn.shopify.com/p11.jpg', ProductVariant::where('shopify_variant_id', 112)->first()->imageUrl()); // then product image
        $this->assertSame('Rupture de stock', ProductVariant::where('shopify_variant_id', 112)->first()->stockLabel());
        $this->assertNotNull($shop->fresh()->catalog_synced_at);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/graphql.json') && $r->hasHeader('X-Shopify-Access-Token', 'shpat_test'));

        // Second full sync: product 12 gone from Shopify → archived (kept), no duplicate.
        $this->fakeCatalog([[
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [$this->gqlProduct(11, 'Tapis 3D Premium', [$this->gqlVariant(111, 'Clio 4', 'TAP-CLIO4', '290.00', 70)])],
        ]]);
        app(CatalogSyncService::class)->sync($shop->fresh(), true);
        $this->assertSame(2, Product::count());
        $this->assertSame('Tapis 3D Premium', $tapis->fresh()->title);
        $this->assertSame('archived', Product::where('shopify_product_id', 12)->value('status'));
        $this->assertNotNull(Product::where('shopify_product_id', 12)->value('deleted_in_shopify_at'));
        $this->assertSame(1, $tapis->variants()->count());
    }

    public function test_sync_follows_pagination(): void
    {
        $shop = $this->shop();
        $this->fakeCatalog([
            ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c1'], 'nodes' => [$this->gqlProduct(1, 'A', [$this->gqlVariant(10, 'Default Title', 'A', '10', 1)])]],
            ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => [$this->gqlProduct(2, 'B', [$this->gqlVariant(20, 'Default Title', 'B', '20', 1)])]],
        ]);
        $r = app(CatalogSyncService::class)->sync($shop, true);
        $this->assertSame(2, $r['products']);
        Http::assertSent(fn ($req) => (json_decode($req->body(), true)['variables']['after'] ?? null) === 'c1');
    }

    public function test_product_and_inventory_webhooks_update_the_catalog(): void
    {
        $shop = $this->seedCatalog();
        ShopifyAppSetting::current()->forceFill(['client_secret' => 'whsec'])->save();

        $send = function (string $topic, array $payload) use ($shop) {
            $raw = json_encode($payload);
            $hmac = base64_encode(hash_hmac('sha256', $raw, 'whsec', true));

            return $this->call('POST', '/shopify/webhooks', [], [], [], [
                'HTTP_X-Shopify-Hmac-Sha256' => $hmac, 'HTTP_X-Shopify-Topic' => $topic,
                'HTTP_X-Shopify-Shop-Domain' => $shop->shop_domain, 'CONTENT_TYPE' => 'application/json',
            ], $raw);
        };

        $send('products/update', [
            'id' => 11, 'title' => 'Tapis 3D v2', 'status' => 'draft', 'updated_at' => '2026-10-03T08:00:00Z',
            'image' => ['src' => 'https://cdn.shopify.com/new.jpg'], 'images' => [['id' => 5, 'src' => 'https://cdn.shopify.com/new.jpg']],
            'variants' => [['id' => 111, 'title' => 'Clio 4', 'sku' => 'TAP-CLIO4', 'price' => '280.00', 'inventory_quantity' => 50, 'inventory_management' => 'shopify', 'inventory_policy' => 'deny', 'inventory_item_id' => 1111, 'image_id' => null, 'position' => 1]],
        ])->assertOk();
        $p = Product::where('shopify_product_id', 11)->first();
        $this->assertSame('Tapis 3D v2', $p->title);
        $this->assertSame('draft', $p->status);
        $this->assertSame(['Tapis'], $p->collections, 'collections kept (not in webhook payload)');
        $this->assertEquals(280, (float) $p->variants()->first()->price);

        $send('products/create', ['id' => 13, 'title' => 'Nouveau', 'status' => 'active', 'variants' => [['id' => 131, 'title' => 'Default Title', 'price' => '99', 'inventory_management' => null]]])->assertOk();
        $this->assertTrue(Product::where('shopify_product_id', 13)->exists());

        $send('inventory_levels/update', ['inventory_item_id' => 1111, 'location_id' => 7, 'available' => 3])->assertOk();
        $this->assertSame(3, ProductVariant::where('shopify_variant_id', 111)->value('inventory_quantity'));

        $send('products/delete', ['id' => 13])->assertOk();
        $this->assertSame('archived', Product::where('shopify_product_id', 13)->value('status'));
    }

    public function test_products_api_filters_and_company_isolation(): void
    {
        $this->signInAdmin();
        $this->seedCatalog();
        $other = Company::create(['name' => 'Autre', 'slug' => 'autre', 'is_active' => true]);
        $op = Product::create(['company_id' => $other->id, 'title' => 'Secret autre société', 'status' => 'active']);
        ProductVariant::create(['product_id' => $op->id, 'company_id' => $other->id, 'title' => 'X', 'sku' => 'SECRET', 'price' => 1]);

        $res = $this->getJson('/api/products')->assertOk();
        $this->assertSame(3, $res->json('meta.total'));
        $res->assertJsonPath('counts.out_of_stock', 1)->assertJsonPath('collections', ['Tapis', 'Volants']);
        $this->getJson('/api/products?q=SECRET')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/products?stock=out_of_stock')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sku', 'TAP-GOLF7');
        $this->getJson('/api/products?stock=in_stock')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/products?q=clio')->assertJsonPath('data.0.stock_label', '78 en stock')
            ->assertJsonPath('data.0.image', 'https://cdn.shopify.com/v111.jpg')
            ->assertJsonPath('data.0.source', 'Lavfast Dev');
        $this->getJson('/api/products?collection=Tapis')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/products/status')->assertJsonPath('shops.0.has_products_scope', true);
    }

    public function test_manual_sync_endpoint_reports_missing_scope(): void
    {
        $this->signInAdmin();
        $this->shop(['scopes' => 'read_orders,read_customers']);
        Http::fake();
        $this->postJson('/api/products/sync')->assertStatus(422)->assertJsonPath('results.0.success', false);
        Http::assertNothingSent();
    }

    public function test_order_line_editing_is_historised_and_recalculates_total(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCatalog();
        $clio = ProductVariant::where('sku', 'TAP-CLIO4')->first();
        $golf = ProductVariant::where('sku', 'TAP-GOLF7')->first();
        $housse = ProductVariant::where('sku', 'HOU-01')->first();
        // Shopify-like order: 1 × Clio 300 + 30 shipping = 330
        $order = Order::create([
            'customer_name' => 'Client', 'total_price' => 330, 'shipping_price' => 30, 'shopify_order_id' => 555,
            'line_items' => [['id' => 9001, 'title' => 'Tapis 3D', 'variant_title' => 'Clio 4', 'quantity' => 1, 'sku' => 'TAP-CLIO4', 'price' => '300.00', 'variant_id' => 111, 'product_id' => 11]],
        ]);
        $show = $this->getJson("/api/orders/{$order->id}")->assertOk();
        $show->assertJsonPath('data.product_image', 'https://cdn.shopify.com/v111.jpg')
            ->assertJsonPath('data.line_items.0.key', 's9001')
            ->assertJsonPath('data.line_items.0.stock_label', '78 en stock');

        // Quantity 1 → 2
        $this->putJson("/api/orders/{$order->id}/items/s9001", ['quantity' => 2])->assertOk()->assertJsonPath('data.amount', 630);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'kind' => 'produits', 'note' => 'Quantité 1 → 2 : Tapis 3D (Clio 4) · Total 330 DH → 630 DH', 'user_id' => $admin->id]);

        // Price 300 → 280
        $this->putJson("/api/orders/{$order->id}/items/s9001", ['price' => 280])->assertOk()->assertJsonPath('data.amount', 590);
        $this->assertTrue(\App\Models\OrderStatusHistory::where('order_id', $order->id)->where('note', 'like', 'Prix 300 DH → 280 DH%')->exists());

        // Add a product at its current Shopify price (150)
        $res = $this->postJson("/api/orders/{$order->id}/items", ['variant_id' => $housse->id, 'quantity' => 1])->assertOk();
        $res->assertJsonPath('data.amount', 740)->assertJsonPath('data.line_items.1.price', '150.00');
        $newKey = $res->json('data.line_items.1.key');
        $this->assertTrue(\App\Models\OrderStatusHistory::where('order_id', $order->id)->where('note', 'like', 'Produit ajouté : Housse volant × 1%')->exists());

        // Replace Clio by Golf (out of stock → warning, company allows preorders by default)
        $res = $this->postJson("/api/orders/{$order->id}/items/s9001/replace", ['variant_id' => $golf->id, 'quantity' => 1])->assertOk();
        $this->assertStringContainsString('Rupture de stock', $res->json('warning'));
        $res->assertJsonPath('data.amount', 500); // 740 - 560 + 320
        $this->assertTrue(\App\Models\OrderStatusHistory::where('order_id', $order->id)->where('note', 'like', 'Tapis 3D (Clio 4) remplacé par Tapis 3D (Golf 7) × 1%')->exists());

        // Remove lines → empty order is possible
        $lines = $res->json('data.line_items');
        $this->deleteJson("/api/orders/{$order->id}/items/{$newKey}")->assertOk();
        $this->deleteJson("/api/orders/{$order->id}/items/{$lines[0]['key']}")->assertOk()->assertJsonPath('data.line_items', [])->assertJsonPath('data.amount', 30);

        $order->refresh();
        $this->assertNotNull($order->items_edited_at);
        $this->assertSame(9001, $order->shopify_line_items[0]['id']); // original Shopify lines kept
        $row = \App\Models\OrderStatusHistory::where('order_id', $order->id)->where('kind', 'produits')->latest('id')->first();
        $this->assertSame('internal', $row->data['scope']);
        $this->assertSame('not_pushed', $row->data['shopify']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/orders/'));
    }

    public function test_out_of_stock_is_blocked_when_company_forbids_preorders(): void
    {
        $this->signInAdmin();
        $this->seedCatalog();
        Setting::setValue('allow_out_of_stock_items', false);
        $golf = ProductVariant::where('sku', 'TAP-GOLF7')->first();
        $order = $this->order(['customer_name' => 'C', 'amount' => 100, 'product_name' => 'X']);
        $this->postJson("/api/orders/{$order->id}/items", ['variant_id' => $golf->id, 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('variant_id');
    }

    public function test_price_change_requires_permission_and_items_require_edit_permission(): void
    {
        $this->signInAdmin();
        $this->seedCatalog();
        $housse = ProductVariant::where('sku', 'HOU-01')->first();
        $order = $this->order(['customer_name' => 'C', 'amount' => 100, 'product_name' => 'X']);

        $agent = User::factory()->create(['role' => User::ROLE_USER]);
        $this->actingAs($agent)->postJson("/api/orders/{$order->id}/items", ['variant_id' => $housse->id, 'quantity' => 1])->assertOk();
        $this->actingAs($agent)->postJson("/api/orders/{$order->id}/items", ['variant_id' => $housse->id, 'quantity' => 1, 'price' => 10])->assertForbidden();
        $this->actingAs($agent)->putJson("/api/orders/{$order->id}/items/i0", ['price' => 10])->assertForbidden();

        Setting::setValue('role_permissions', ['orders.edit_items' => ['admin']]);
        $this->actingAs($agent)->postJson("/api/orders/{$order->id}/items", ['variant_id' => $housse->id, 'quantity' => 1])->assertForbidden();
    }

    public function test_variant_of_another_company_cannot_be_added(): void
    {
        $this->signInAdmin();
        $other = Company::create(['name' => 'Autre', 'slug' => 'autre', 'is_active' => true]);
        $p = Product::create(['company_id' => $other->id, 'title' => 'Secret', 'status' => 'active']);
        $v = ProductVariant::create(['product_id' => $p->id, 'company_id' => $other->id, 'price' => 1]);
        $order = $this->order(['customer_name' => 'C', 'amount' => 100]);
        $this->postJson("/api/orders/{$order->id}/items", ['variant_id' => $v->id, 'quantity' => 1])->assertNotFound();
    }

    public function test_shopify_update_does_not_overwrite_internally_edited_lines(): void
    {
        $this->signInAdmin();
        $shop = $this->seedCatalog();
        $sync = app(\App\Services\Shopify\OrderSyncService::class);
        $payload = ['id' => 777, 'name' => '#1777', 'order_number' => 1777, 'total_price' => '300.00', 'currency' => 'MAD',
            'line_items' => [['id' => 1, 'title' => 'Tapis 3D', 'quantity' => 1, 'price' => '300.00', 'sku' => 'TAP-CLIO4', 'variant_id' => 111, 'product_id' => 11]]];
        $order = $sync->upsertFromShopifyPayload($shop, $payload);
        // Connected orders are edited in Shopify (Part 2). This test keeps the legacy freeze:
        // lines marked items_edited_at stay local when a later Shopify payload arrives.
        $order->forceFill([
            'items_edited_at' => now(),
            'line_items' => array_merge($order->line_items, [[
                'id' => null, 'title' => 'Housse volant', 'quantity' => 1, 'price' => '150.00', 'sku' => 'HOU-01',
            ]]),
            'total_price' => 450,
        ])->save();

        $payload['note'] = 'Nouvelle note Shopify';
        $sync->upsertFromShopifyPayload($shop, $payload);
        $order->refresh();
        $this->assertCount(2, $order->line_items);
        $this->assertEquals(450, (float) $order->total_price);
        $this->assertSame('Nouvelle note Shopify', $order->note);
        $this->assertCount(1, $order->shopify_line_items);
    }
}
