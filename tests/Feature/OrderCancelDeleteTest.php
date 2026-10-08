<?php

namespace Tests\Feature;

use App\Models\Closing;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Mission;
use App\Models\Order;
use App\Models\OrderDeletion;
use App\Models\SavRequest;
use App\Models\ShopifyShop;
use App\Models\ShopifySyncLog;
use App\Models\SpeedafShipment;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderCancelDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_draft_is_soft_deleted_with_an_audit_row(): void
    {
        $admin = $this->signInAdmin();
        $order = $this->draft();
        $this->deleteJson("/api/orders/{$order->id}/draft", ['reason' => 'Doublon saisi'])->assertOk();
        $this->assertNull(Order::query()->find($order->id));
        $kept = Order::withoutGlobalScope('not_deleted')->find($order->id);
        $this->assertNotNull($kept->deleted_at);
        $audit = OrderDeletion::query()->where('order_id', $order->id)->first();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('Doublon saisi', $audit->reason);
        $this->assertSame('Sara', $audit->snapshot['customer_name']);
    }

    public function test_orders_with_consequences_cannot_be_deleted(): void
    {
        $this->signInAdmin();
        $shop = $this->shop();
        $shopify = $this->draft();
        $shopify->forceFill(['shopify_shop_id' => $shop->id, 'shopify_order_id' => 1])->save();
        $this->deleteJson("/api/orders/{$shopify->id}/draft")->assertStatus(422)->assertJsonPath('errors.order.0', 'Commande déjà créée dans Shopify.');

        $shipped = $this->draft();
        SpeedafShipment::create(['order_id' => $shipped->id, 'state' => 'created', 'bill_code' => 'SP1']);
        $this->deleteJson("/api/orders/{$shipped->id}/draft")->assertStatus(422)->assertJsonPath('errors.order.0', 'Un colis transporteur existe déjà.');

        $mission = $this->draft();
        Mission::create(['order_id' => $mission->id, 'type' => 'livraison', 'contact_name' => 'Sara', 'status' => 'a_faire']);
        $this->deleteJson("/api/orders/{$mission->id}/draft")->assertStatus(422)->assertJsonPath('errors.order.0', 'Une mission ou un livreur est déjà lié.');

        $paid = $this->draft();
        $paid->forceFill(['amount_paid' => 10, 'financial_status' => 'partially_paid', 'total_price' => 40])->save();
        $this->deleteJson("/api/orders/{$paid->id}/draft")->assertStatus(422)->assertJsonPath('errors.order.0', 'Un paiement est déjà enregistré.');

        $driver = Driver::create(['name' => 'Yassine', 'phone' => '0600000000', 'is_active' => true]);
        $closing = Closing::create([
            'driver_id' => $driver->id,
            'closing_date' => now()->toDateString(),
            'closed_at' => now(),
        ]);
        $closed = $this->draft();
        $closed->forceFill(['closing_id' => $closing->id])->save();
        $this->deleteJson("/api/orders/{$closed->id}/draft")->assertStatus(422)->assertJsonPath('errors.order.0', 'Commande incluse dans une clôture.');

        $delivered = $this->draft();
        $delivered->forceFill(['delivered_at' => now()])->save();
        $this->deleteJson("/api/orders/{$delivered->id}/draft")->assertStatus(422)->assertJsonPath('errors.order.0', 'Commande déjà livrée.');

        $sav = $this->draft();
        SavRequest::create(['order_id' => $sav->id, 'type' => 'retour', 'status' => 'ouvert', 'reason' => 'Taille', 'customer_name' => 'Sara']);
        $this->deleteJson("/api/orders/{$sav->id}/draft")->assertStatus(422)->assertJsonPath('errors.order.0', 'Un dossier SAV existe déjà.');
    }

    public function test_shopify_cancel_does_not_refund_unless_an_admin_opts_in(): void
    {
        $admin = $this->signInAdmin();
        $order = $this->synced(['financial_status' => 'paid', 'amount_paid' => 275, 'total_price' => 275, 'confirmation_status' => 'confirmed']);
        $sent = null;
        $this->http(function ($request) use (&$sent) {
            $sent = json_decode(json_encode($request->data()['variables']), true);

            return Http::response(['data' => ['orderCancel' => [
                'job' => ['id' => 'gid://shopify/Job/1', 'done' => false],
                'orderCancelUserErrors' => [],
                'userErrors' => [],
            ]]]);
        });

        $this->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'client', 'comment' => 'Ne veut plus'])->assertOk();
        $this->assertFalse($sent['refundMethod']['originalPaymentMethodsRefund']);
        $this->assertTrue($sent['restock']);
        $this->assertFalse($sent['notifyCustomer']);
        $this->assertSame('CUSTOMER', $sent['reason']);
        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('confirmed', $fresh->confirmation_status);
        $this->assertSame('pending', $fresh->shopify_sync_status);
        $this->assertEquals(0, (float) $fresh->amount_due);
        $history = $fresh->histories()->where('kind', 'commande')->where('status_code', 'order_cancelled')->first();
        $this->assertSame($admin->id, $history->user_id);
        $this->assertSame('Client a annulé', $history->data['reason']);
        $this->assertSame('confirmed', $history->data['before']['confirmation']);
        $this->assertSame('cancelled', $history->data['after']['status']);
        $this->assertSame('pending', $history->data['before']['status']);

        $agent = User::factory()->create(['role' => User::ROLE_USER, 'company_id' => Company::default()->id]);
        Setting::setValue('role_permissions', ['orders.cancel' => ['admin', 'user']]);
        $paid = $this->synced(['financial_status' => 'paid', 'amount_paid' => 80, 'total_price' => 80, 'shopify_order_id' => 8802]);
        $this->http();
        $this->actingAs($agent)->postJson("/api/orders/{$paid->id}/cancel", ['reason' => 'client', 'refund' => true])->assertForbidden();
        Http::assertNothingSent();
        $this->assertNotSame('cancelled', $paid->fresh()->status);

        $this->actingAs($admin);
        $this->http(fn () => Http::response(['data' => ['orderCancel' => [
            'job' => ['id' => 'gid://shopify/Job/2', 'done' => true],
            'orderCancelUserErrors' => [],
            'userErrors' => [],
        ]]]));
        $this->postJson("/api/orders/{$paid->id}/cancel", ['reason' => 'doublon', 'refund' => true])->assertOk();
        Http::assertSent(function ($r) {
            $vars = json_decode(json_encode($r->data()['variables']), true);

            return ($vars['refundMethod']['originalPaymentMethodsRefund'] ?? null) === true;
        });
    }

    public function test_cancel_is_refused_when_a_parcel_is_active_or_the_order_is_closed(): void
    {
        $this->signInAdmin();
        $parcel = $this->synced();
        SpeedafShipment::create(['order_id' => $parcel->id, 'state' => 'created', 'bill_code' => 'SP9']);
        $this->http();
        $this->postJson("/api/orders/{$parcel->id}/cancel", ['reason' => 'client'])
            ->assertStatus(422)
            ->assertJsonPath('errors.order.0', 'Annulez d’abord le colis chez Speedaf');
        Http::assertNothingSent();
        $this->assertSame('pending', $parcel->fresh()->status);

        $delivered = $this->synced(['shopify_order_id' => 8803, 'delivered_at' => now()]);
        $this->postJson("/api/orders/{$delivered->id}/cancel", ['reason' => 'stock'])
            ->assertStatus(422)
            ->assertJsonPath('errors.order.0', 'Commande livrée / clôturée : utilisez un retour (SAV)');

        $driver = Driver::create(['name' => 'Yassine', 'phone' => '0600000099', 'is_active' => true]);
        $closing = Closing::create(['driver_id' => $driver->id, 'closing_date' => now()->toDateString(), 'closed_at' => now()]);
        $closed = $this->synced(['shopify_order_id' => 8804, 'closing_id' => $closing->id]);
        $this->postJson("/api/orders/{$closed->id}/cancel", ['reason' => 'autre', 'comment' => 'Clôture'])
            ->assertStatus(422);
    }

    public function test_unstarted_mission_is_cancelled_with_the_order(): void
    {
        $this->signInAdmin();
        $order = $this->synced(['shopify_order_id' => null, 'shopify_shop_id' => null]);
        $mission = Mission::create(['order_id' => $order->id, 'type' => 'livraison', 'contact_name' => 'Sara', 'status' => 'a_faire']);
        $this->http();
        $this->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'saisie', 'comment' => 'Mauvaise ville'])->assertOk();
        $this->assertSame('annulee', $mission->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('to_confirm', $order->fresh()->confirmation_status);
        Http::assertNothingSent();
    }

    public function test_shopify_rejection_changes_nothing_locally(): void
    {
        $this->signInAdmin();
        $order = $this->synced(['confirmation_status' => 'confirmed']);
        $this->http(fn () => Http::response(['data' => ['orderCancel' => [
            'job' => null,
            'orderCancelUserErrors' => [['field' => ['orderId'], 'message' => 'Commande déjà expédiée', 'code' => 'INVALID']],
            'userErrors' => [],
        ]]]));
        $this->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'client'])->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->cancelled_at);
        $this->assertSame('confirmed', $order->fresh()->confirmation_status);
        $log = ShopifySyncLog::query()->where('action', 'orderCancel')->where('status', 'failed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Réessayer', (string) $log->error);
    }

    public function test_bulk_cancel_and_delete_return_per_order_results(): void
    {
        $this->signInAdmin();
        $ok = $this->synced(['shopify_order_id' => null, 'shopify_shop_id' => null]);
        $blocked = $this->synced(['shopify_order_id' => 8810]);
        SpeedafShipment::create(['order_id' => $blocked->id, 'state' => 'created', 'bill_code' => 'SP2']);
        $draft = $this->draft();
        $created = $this->synced(['shopify_order_id' => null, 'shopify_shop_id' => null, 'flow_state' => 'created']);

        $this->http();
        $cancel = $this->postJson('/api/orders/cancel', [
            'order_ids' => [$ok->id, $blocked->id],
            'reason' => 'client',
        ])->assertOk();
        $rows = collect($cancel->json('results'))->keyBy('order_id');
        $this->assertTrue($rows[$ok->id]['ok']);
        $this->assertFalse($rows[$blocked->id]['ok']);
        $this->assertStringContainsString('Speedaf', $rows[$blocked->id]['message']);

        $delete = $this->postJson('/api/orders/delete-drafts', [
            'order_ids' => [$draft->id, $created->id],
        ])->assertOk();
        $deleted = collect($delete->json('results'))->keyBy('order_id');
        $this->assertTrue($deleted[$draft->id]['ok']);
        $this->assertFalse($deleted[$created->id]['ok']);
        $this->assertNull(Order::query()->find($draft->id));
        $this->assertNotNull(Order::query()->find($created->id));
    }

    public function test_cancel_and_delete_permissions_are_enforced(): void
    {
        $order = $this->synced(['shopify_order_id' => null, 'shopify_shop_id' => null]);
        $draft = $this->draft();
        $agent = User::factory()->create(['role' => User::ROLE_USER, 'company_id' => Company::default()->id]);
        Setting::setValue('role_permissions', [
            'orders.cancel' => ['admin'],
            'orders.delete_draft' => ['admin'],
        ]);
        $this->actingAs($agent);
        $this->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'client'])->assertForbidden();
        $this->deleteJson("/api/orders/{$draft->id}/draft")->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($draft->fresh()->deleted_at);
    }

    public function test_another_companys_order_cannot_be_cancelled_deleted_or_retried(): void
    {
        $admin = $this->signInAdmin();
        $admin->resolveCompanyId();
        $other = Company::create(['name' => 'Autre', 'slug' => 'autre-annulation', 'is_active' => true]);
        $foreign = Order::create([
            'company_id' => $other->id,
            'customer_name' => 'Ailleurs',
            'phone' => '0666666666',
            'total_price' => 80,
            'source' => 'Lav’Fast Flow',
            'flow_state' => 'created',
            'status' => 'pending',
            'confirmation_status' => 'confirmed',
            'shopify_order_id' => 4242,
            'creation_key' => (string) \Illuminate\Support\Str::uuid(),
            'shopify_sync_status' => 'synced',
        ]);
        $failed = Order::create([
            'company_id' => $other->id,
            'customer_name' => 'Brouillon ailleurs',
            'phone' => '0666666667',
            'total_price' => 20,
            'source' => 'Lav’Fast Flow',
            'flow_state' => 'draft',
            'status' => 'pending',
            'confirmation_status' => 'to_confirm',
            'creation_key' => (string) \Illuminate\Support\Str::uuid(),
            'shopify_sync_status' => 'failed',
        ]);
        $this->http();
        $this->postJson("/api/orders/{$foreign->id}/cancel", ['reason' => 'client'])->assertNotFound();
        $this->deleteJson("/api/orders/{$failed->id}/draft")->assertNotFound();
        $this->postJson("/api/orders/{$failed->id}/flow-retry")->assertNotFound();
        $bulk = $this->postJson('/api/orders/cancel', [
            'order_ids' => [$foreign->id],
            'reason' => 'client',
        ])->assertOk();
        $this->assertFalse($bulk->json('results.0.ok'));
        $this->assertSame('Commande introuvable.', $bulk->json('results.0.message'));
        $deleted = $this->postJson('/api/orders/delete-drafts', ['order_ids' => [$failed->id]])->assertOk();
        $this->assertSame('Commande introuvable.', $deleted->json('results.0.message'));
        Http::assertNothingSent();
        $this->assertSame('pending', $foreign->fresh()->status);
        $this->assertNull($failed->fresh()->deleted_at);
    }

    private function http(?callable $callback = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake($callback ?? fn () => Http::response(['data' => []], 200));
    }

    private function shop(): ShopifyShop
    {
        return ShopifyShop::create([
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

    private function draft(): Order
    {
        return Order::create([
            'company_id' => Company::default()->id,
            'customer_name' => 'Sara',
            'phone' => '0612000000',
            'total_price' => 100,
            'source' => 'Lav’Fast Flow',
            'flow_state' => 'draft',
            'creation_key' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'pending',
            'confirmation_status' => 'to_confirm',
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function synced(array $extra = []): Order
    {
        $shop = ShopifyShop::query()->first() ?: $this->shop();

        return Order::create($extra + [
            'company_id' => Company::default()->id,
            'shopify_shop_id' => $shop->id,
            'shopify_order_id' => 8801,
            'name' => '#8801',
            'order_number' => '8801',
            'customer_name' => 'Sara',
            'phone' => '0612000001',
            'total_price' => 275,
            'amount_paid' => 0,
            'financial_status' => 'pending',
            'source' => 'Lav’Fast Flow',
            'flow_state' => 'created',
            'status' => 'pending',
            'confirmation_status' => 'to_confirm',
        ]);
    }
}
