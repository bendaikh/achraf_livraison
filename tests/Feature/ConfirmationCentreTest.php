<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShopifyShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** T5 — Centre de confirmation (stats, queue navigation, calls, discounts, channel). */
class ConfirmationCentreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_stats_are_zero_without_data_and_hide_comparisons(): void
    {
        $this->signInAdmin();
        $this->getJson('/api/confirmation/stats')->assertOk()
            ->assertJsonPath('calls.today', 0)
            ->assertJsonPath('calls.yesterday', null)
            ->assertJsonPath('confirmed.today', 0)
            ->assertJsonPath('failed.today', 0)
            ->assertJsonPath('to_confirm', 0)
            ->assertJsonPath('mine.calls_today', 0);
    }

    public function test_calls_confirmations_and_cancellations_feed_the_counters(): void
    {
        $admin = $this->signInAdmin();
        $a = $this->order(['customer_name' => 'A', 'amount' => 100, 'product_name' => 'Sac']);
        $b = $this->order(['customer_name' => 'B', 'amount' => 100, 'product_name' => 'Sac']);
        $this->order(['customer_name' => 'C', 'amount' => 100, 'product_name' => 'Sac']);

        $this->postJson("/api/confirmation/orders/{$a->id}/calls", ['result' => 'answered', 'note' => 'OK client'])->assertCreated()
            ->assertJsonPath('call.result_label', 'Répondu')
            ->assertJsonPath('call.user_name', $admin->name);
        $this->postJson("/api/confirmation/orders/{$b->id}/calls", ['result' => 'wrong_number'])->assertCreated();
        $this->postJson("/api/confirmation/orders/{$a->id}/calls", ['result' => 'bad'])->assertUnprocessable();
        $this->postJson("/api/confirmation/orders/{$a->id}/confirm", ['channel' => 'whatsapp'])->assertOk()
            ->assertJsonPath('order.confirmation_channel', 'whatsapp');
        $this->postJson("/api/confirmation/orders/{$b->id}/cancel", ['reason' => 'Numéro incorrect'])->assertOk();

        $this->getJson('/api/confirmation/stats')->assertOk()
            ->assertJsonPath('calls.today', 2)
            ->assertJsonPath('confirmed.today', 1)
            ->assertJsonPath('failed.today', 1)
            ->assertJsonPath('to_confirm', 1)
            ->assertJsonPath('mine.confirmed_today', 1);

        $detail = $this->getJson("/api/confirmation/orders/{$a->id}")->assertOk();
        $detail->assertJsonPath('order.calls.0.result', 'answered')
            ->assertJsonPath('order.full.id', $a->id);
        $this->assertTrue(OrderStatusHistory::where('order_id', $a->id)->where('kind', 'appel')->exists());
    }

    public function test_queue_navigation_prev_next_and_after_processing(): void
    {
        $this->signInAdmin();
        $o1 = $this->order(['customer_name' => 'Un', 'amount' => 100]);
        $o2 = $this->order(['customer_name' => 'Deux', 'amount' => 100]);
        $o3 = $this->order(['customer_name' => 'Trois', 'amount' => 100]);
        // Newest first (same timestamp → id desc): o3, o2, o1
        $this->getJson("/api/confirmation/orders/{$o2->id}/siblings")->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('position', 2)
            ->assertJsonPath('prev_id', $o3->id)->assertJsonPath('next_id', $o1->id);
        $this->assertSame([$o3->id, $o2->id, $o1->id], collect($this->getJson('/api/confirmation/orders')->json('orders'))->pluck('id')->all());

        $this->postJson("/api/confirmation/orders/{$o3->id}/confirm")->assertOk();
        // Processed order left the queue → next = head of the queue.
        $this->getJson("/api/confirmation/orders/{$o3->id}/siblings")->assertJsonPath('in_queue', false)->assertJsonPath('next_id', $o2->id)->assertJsonPath('total', 2);
    }

    public function test_postponed_order_returns_to_queue_when_due(): void
    {
        $this->signInAdmin();
        $o = $this->order(['customer_name' => 'Rappel', 'amount' => 100]);
        $this->postJson("/api/confirmation/orders/{$o->id}/postpone", ['recall_at' => now()->addHour()->toIso8601String(), 'note' => 'Après 18h'])->assertOk();
        $this->getJson('/api/confirmation/stats')->assertJsonPath('to_confirm', 0);
        $this->travel(2)->hours();
        $this->getJson('/api/confirmation/stats')->assertJsonPath('to_confirm', 1)->assertJsonPath('postponed_due', 1);
    }

    public function test_discounts_change_total_with_history_and_permission(): void
    {
        $this->signInAdmin();
        $o = $this->order(['customer_name' => 'Remise', 'amount' => 300, 'product_name' => 'Sac']);

        $res = $this->postJson("/api/confirmation/orders/{$o->id}/discounts", ['type' => 'percent', 'value' => 10, 'reason' => 'Geste commercial'])->assertCreated();
        $this->assertEquals(30, $res->json('discount.amount'));
        $this->assertEquals(270, (float) $o->fresh()->total_price);
        $this->assertEquals(30, (float) $o->fresh()->discount_total);
        $this->postJson("/api/confirmation/orders/{$o->id}/discounts", ['type' => 'amount', 'value' => 500])->assertUnprocessable();

        $this->deleteJson("/api/confirmation/orders/{$o->id}/discounts/{$res->json('discount.id')}")->assertOk();
        $this->assertEquals(300, (float) $o->fresh()->total_price);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $o->id)->where('kind', 'remise')->count());
        $this->getJson("/api/confirmation/orders/{$o->id}")->assertJsonPath('order.discounts.0.removed_at', fn ($v) => $v !== null);

        // Agents without the permission cannot add discounts.
        Setting::setValue('role_permissions', ['orders.discount' => []]);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->postJson("/api/confirmation/orders/{$o->id}/discounts", ['type' => 'amount', 'value' => 10])->assertForbidden();
        // …but can still log calls.
        $this->postJson("/api/confirmation/orders/{$o->id}/calls", ['result' => 'no_answer'])->assertCreated();
    }

    public function test_drivers_cannot_use_confirmation_api(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_LIVREUR]));
        $this->getJson('/api/confirmation/orders')->assertForbidden();
        $this->getJson('/api/confirmation/stats')->assertForbidden();
    }

    public function test_payment_indicator_matches_orders_driver_and_carrier_preview(): void
    {
        $this->signInAdmin();
        $companyId = Company::default()->id;
        $driver = Driver::create([
            'name' => 'Yassine',
            'user_id' => User::factory()->create(['role' => User::ROLE_LIVREUR])->id,
        ]);

        $cases = [
            ['paye', 450, null, 'Payée par carte – 0 DH à encaisser', 0.0],
            ['cod', 450, 0, 'COD – 450 DH à encaisser', 450.0],
            ['partial', 450, 100, 'Partiellement payée – 350 DH à encaisser', 350.0],
        ];

        foreach ($cases as [$kind, $total, $paid, $label, $due]) {
            $order = $this->order([
                'customer_name' => $kind,
                'customer_phone' => '061200000'.$paid,
                'amount' => $total,
                'city' => 'Casablanca',
                'address' => '1 rue des tests',
                'payment_method' => $kind === 'paye' ? 'paye' : 'cod',
            ]);
            if ($kind === 'partial') {
                $order->forceFill(['amount_paid' => $paid, 'financial_status' => 'partially_paid'])->save();
            }
            $order->forceFill(['driver_id' => $driver->id, 'company_id' => $companyId])->save();
            $order->refresh();

            $centre = $this->getJson("/api/confirmation/orders/{$order->id}")->assertOk();
            $this->assertSame($label, $centre->json('order.payment_indicator'));
            $this->assertEquals($due, $centre->json('order.amount_due'));

            $resource = $this->getJson("/api/orders/{$order->id}")->assertOk();
            $this->assertEquals($due, $resource->json('data.amount_due'));
            $this->assertSame($label, $resource->json('data.payment_indicator'));

            $mission = $this->getJson("/api/driver/missions/{$order->id}?driver_id={$driver->id}")->assertOk();
            $this->assertEquals($due, $mission->json('order.amount_due'));

            $preview = $this->postJson('/api/delivery-modes/speedaf/check', ['order_ids' => [$order->id]])->assertOk();
            $this->assertEquals($due, $preview->json('rows.0.amount_due'));
        }
    }

    public function test_centre_shows_client_history_photos_and_shopify_replace_is_not_local_only(): void
    {
        $this->signInAdmin();
        $companyId = Company::default()->id;
        $product = Product::create([
            'company_id' => $companyId,
            'title' => 'Tapis coffre',
            'status' => 'active',
            'image_url' => 'https://cdn.example/product.jpg',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'company_id' => $companyId,
            'shopify_variant_id' => 555,
            'title' => 'Noir',
            'sku' => 'TAP-555',
            'price' => 100,
            'image_url' => 'https://cdn.example/variant.jpg',
            'inventory_quantity' => 4,
        ]);
        $first = $this->order(['customer_name' => 'Sara', 'customer_phone' => '0612345678', 'amount' => 100, 'city' => 'Rabat', 'address' => '12 rue']);
        $first->forceFill([
            'line_items' => [[
                'id' => 1, 'title' => 'Tapis coffre', 'variant_id' => 555, 'sku' => 'TAP-555', 'quantity' => 1, 'price' => '100.00',
            ]],
        ])->save();
        $this->order(['customer_name' => 'Sara', 'customer_phone' => '0612345678', 'amount' => 80]);
        $this->postJson('/api/clients/'.$first->fresh()->phone_key.'/block', ['reason' => 'Impayé'])->assertOk();

        $detail = $this->getJson("/api/confirmation/orders/{$first->id}")->assertOk();
        $this->assertGreaterThanOrEqual(1, $detail->json('order.client_history.previous'));
        $this->assertSame('Impayé', $detail->json('order.client_blocked.reason'));
        $this->assertSame('https://cdn.example/variant.jpg', $detail->json('order.products.0.image_url'));

        $shop = ShopifyShop::create([
            'company_id' => $companyId,
            'shop_domain' => 'centre-shop.myshopify.com',
            'shop_name' => 'Centre',
            'access_token' => 'shpat_test',
            'scopes' => 'write_orders,write_order_edits',
            'granted_scopes' => 'write_orders,write_order_edits',
            'is_active' => true,
            'installed_at' => now(),
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'company_id' => $companyId,
            'shopify_variant_id' => 777,
            'title' => 'Beige',
            'sku' => 'TAP-777',
            'price' => 200,
        ]);
        $shopifyOrder = Order::create([
            'company_id' => $companyId,
            'shopify_shop_id' => $shop->id,
            'shopify_order_id' => 4242,
            'customer_name' => 'Shopify',
            'phone' => '0611112233',
            'total_price' => 100,
            'currency' => 'MAD',
            'delivery_status' => 'in_progress',
            'line_items' => [[
                'id' => 1, 'title' => 'Ancien tapis', 'quantity' => 1, 'price' => '100.00', 'variant_id' => 555,
            ]],
        ]);
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $query = (string) ($request->data()['query'] ?? '');
            if (str_contains($query, 'orderEditBegin')) {
                $seen[] = 'orderEditBegin';

                return Http::response(['data' => ['orderEditBegin' => [
                    'calculatedOrder' => null,
                    'userErrors' => [['field' => ['id'], 'message' => 'refusé']],
                ]]], 200);
            }

            return Http::response(['errors' => [['message' => 'unexpected']]], 200);
        });

        $this->postJson("/api/orders/{$shopifyOrder->id}/items/s1/replace", [
            'variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertStatus(422);
        $this->assertContains('orderEditBegin', $seen);
        $this->assertSame('Ancien tapis', $shopifyOrder->fresh()->line_items[0]['title']);
        $this->assertSame('in_progress', $shopifyOrder->fresh()->delivery_status);
        $this->assertSame('to_confirm', $shopifyOrder->fresh()->confirmation_status);
    }

    public function test_queue_remaining_count_drops_after_an_action(): void
    {
        $this->signInAdmin();
        $a = $this->order(['customer_name' => 'Un', 'amount' => 10]);
        $b = $this->order(['customer_name' => 'Deux', 'amount' => 10]);
        $before = $this->getJson("/api/confirmation/orders/{$a->id}/siblings")->assertOk();
        $this->assertSame(2, $before->json('total'));
        $this->postJson("/api/confirmation/orders/{$a->id}/confirm")->assertOk();
        $after = $this->getJson("/api/confirmation/orders/{$a->id}/siblings")->assertOk();
        $this->assertFalse($after->json('in_queue'));
        $this->assertSame(1, $after->json('total'));
        $this->assertSame($b->id, $after->json('next_id'));
    }
}
