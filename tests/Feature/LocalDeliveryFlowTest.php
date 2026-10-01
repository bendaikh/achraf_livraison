<?php

namespace Tests\Feature;

use App\Models\DeliveryStatus;
use App\Models\Driver;
use App\Models\Mission;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Remote modules (Confirmation, Affectation, Livreurs with login, Mes missions) wired onto the
 * configurable delivery statuses, the tariff snapshots and the closings.
 */
class LocalDeliveryFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function driverWithLogin(string $name, array $tariffs = []): Driver
    {
        $user = User::factory()->create(['name' => $name, 'role' => User::ROLE_LIVREUR]);

        return Driver::create(['name' => $name, 'user_id' => $user->id] + $tariffs);
    }

    public function test_admin_api_requires_an_admin_session(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
        $this->getJson('/api/meta')->assertUnauthorized();

        $driver = $this->driverWithLogin('Yassine');
        $this->actingAs($driver->user)->getJson('/api/orders')->assertForbidden();
        $this->actingAs($driver->user)->getJson('/api/driver/missions')->assertOk();
    }

    public function test_creating_a_driver_creates_his_login_account(): void
    {
        $this->signInAdmin();
        $res = $this->postJson('/api/drivers', [
            'name' => 'Karim', 'phone' => '0600000000', 'email' => 'karim@demo.test', 'password' => 'secret123',
            'tariff_livraison' => 18,
        ])->assertCreated()->assertJsonPath('data.email', 'karim@demo.test')->assertJsonPath('data.tariffs.livraison', 18);

        $driver = Driver::findOrFail($res->json('data.id'));
        $this->assertSame(User::ROLE_LIVREUR, $driver->user->role);
        $this->getJson('/api/drivers')->assertJsonPath('data.0.stats.orders_assigned', 0);
        $this->getJson('/api/drivers/active')->assertJsonPath('drivers.0.name', 'Karim');
    }

    public function test_confirmation_then_assignment_screen_snapshot_tariff_and_use_configured_statuses(): void
    {
        $admin = $this->signInAdmin();
        $driver = $this->driverWithLogin('Yassine', ['tariff_livraison' => 20]);
        $order = $this->order(['customer_name' => 'Client', 'amount' => 199, 'city' => 'Casablanca', 'product_name' => 'Sac']);

        // Confirmation queue (remote screen) → "À attribuer" status + history.
        $this->postJson("/api/confirmation/orders/{$order->id}/confirm")->assertOk();
        $order->refresh();
        $this->assertSame('confirmed', $order->confirmation_status);
        $this->assertSame('to_assign', $order->delivery_status);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'kind' => 'confirmation', 'status_code' => 'confirmed', 'user_id' => $admin->id]);

        $this->getJson('/api/assignment/orders')->assertOk()->assertJsonPath('counts.to_assign', 1)->assertJsonPath('orders.0.can_assign', true);

        // Affectation (remote screen) → livraison mission with the 20 DH snapshot, "Attribuée".
        $this->postJson('/api/assignment/assign', ['order_ids' => [$order->id], 'driver_id' => $driver->id])->assertOk();
        $order->refresh();
        $this->assertSame('assigned', $order->delivery_status);
        $this->assertSame($admin->id, $order->assigned_by);
        $mission = Mission::where('order_id', $order->id)->where('type', 'livraison')->firstOrFail();
        $this->assertEquals(20, (float) $mission->driver_price);
        $this->assertSame('Casablanca', $mission->city);
        $this->getJson('/api/assignment/orders?filter=assigned')->assertJsonPath('meta.total', 1)->assertJsonPath('orders.0.delivery_status_label', 'Attribuée');

        // Tariff change later never touches the snapshot.
        $this->putJson("/api/drivers/{$driver->id}", ['tariff_livraison' => 30])->assertOk();
        $this->assertEquals(20, (float) $mission->fresh()->driver_price);
    }

    public function test_driver_space_actions_go_through_configurable_statuses_and_feed_closing(): void
    {
        $this->signInAdmin();
        $driver = $this->driverWithLogin('Yassine', ['tariff_livraison' => 20]);
        $order = $this->order(['customer_name' => 'Client', 'amount' => 250]);
        $this->postJson("/api/orders/{$order->id}/confirmation", ['confirmation_status' => 'confirmed'])->assertOk();
        $this->postJson('/api/assignment/assign', ['order_ids' => [$order->id], 'driver_id' => $driver->id])->assertOk();

        // Renamed status and a brand new "succès" status chosen for the "Livrée" driver action.
        DeliveryStatus::where('code', 'in_progress')->update(['name' => 'Chez le livreur']);
        $custom = DeliveryStatus::create(['name' => 'Livrée au client', 'code' => 'livree_client', 'color' => '#22c55e', 'category' => 'succes', 'required_fields' => ['collected_amount']]);
        Setting::setValue('driver_action_status_ids', ['deliver' => $custom->id]);

        $this->actingAs($driver->user);
        $this->getJson('/api/driver/missions')->assertOk()->assertJsonPath('counts.active', 1)->assertJsonPath('orders.0.can_act', true);
        $this->postJson("/api/driver/missions/{$order->id}/deliver", ['amount_collected' => 240])->assertOk()
            ->assertJsonPath('order.delivery_status', 'livree_client')
            ->assertJsonPath('order.delivery_status_label', 'Livrée au client');

        $order->refresh();
        $this->assertEquals(240, (float) $order->amount_collected);
        $this->assertNotNull($order->delivery_taken_at);
        $this->assertSame(['livree_client', 'in_progress', 'assigned', 'to_assign'], $order->histories()->where('kind', 'livraison')->pluck('status_code')->all());
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'status_code' => 'in_progress', 'status_name' => 'Chez le livreur']);
        $this->assertSame('terminee', Mission::where('order_id', $order->id)->value('status'));
        $this->assertEquals(240, $driver->fresh()->codHeldAmount());

        // Closing (Clôture du jour): COD 240, commission 20 — kept separate; COD flagged as remitted.
        $this->signInAdmin();
        $this->getJson('/api/closings/pending')->assertJsonPath('data.0.cod', 240)->assertJsonPath('data.0.commissions', 20);
        $this->postJson('/api/closings', ['driver_id' => $driver->id])->assertCreated();
        $this->assertNotNull($order->fresh()->cod_remitted_at);
        $this->assertEquals(0, $driver->fresh()->codHeldAmount());
    }

    public function test_driver_failure_requires_reason_and_order_can_be_reassigned_with_new_mission(): void
    {
        $this->signInAdmin();
        $a = $this->driverWithLogin('A', ['tariff_livraison' => 20]);
        $b = $this->driverWithLogin('B', ['tariff_livraison' => 25]);
        $order = $this->order(['customer_name' => 'Client', 'amount' => 100]);
        $this->postJson("/api/confirmation/orders/{$order->id}/confirm")->assertOk();
        $this->postJson('/api/assignment/assign', ['order_ids' => [$order->id], 'driver_id' => $a->id])->assertOk();

        $this->actingAs($a->user);
        $this->postJson("/api/driver/missions/{$order->id}/fail", [])->assertStatus(422);
        $this->assertSame('assigned', $order->fresh()->delivery_status); // nothing applied
        $this->postJson("/api/driver/missions/{$order->id}/fail", ['reason' => 'Adresse introuvable'])->assertOk()
            ->assertJsonPath('order.delivery_status', 'failed');

        $this->signInAdmin();
        $this->getJson('/api/assignment/orders?filter=failed')->assertJsonPath('meta.total', 1);
        $this->postJson('/api/assignment/assign', ['order_ids' => [$order->id], 'driver_id' => $b->id])->assertOk();

        $missions = Mission::where('order_id', $order->id)->where('type', 'livraison')->orderBy('id')->get();
        $this->assertCount(2, $missions);
        $this->assertSame(['echouee', 'a_faire'], $missions->pluck('status')->all());
        $this->assertEquals([20, 25], $missions->pluck('driver_price')->map(fn ($p) => (float) $p)->all());
        $this->assertSame('assigned', $order->fresh()->delivery_status);
    }

    public function test_manual_order_without_shopify_shop(): void
    {
        $this->signInAdmin();
        $res = $this->postJson('/api/orders', [
            'customer_name' => 'Client WhatsApp', 'customer_phone' => '0612345678', 'city' => 'Rabat', 'address' => '12 rue X',
            'product_name' => 'Montre', 'quantity' => 2, 'amount' => 300, 'payment_method' => 'cod', 'source' => 'WhatsApp',
        ])->assertCreated();

        $res->assertJsonPath('data.city', 'Rabat')->assertJsonPath('data.quantity', 2)->assertJsonPath('data.amount', 300)
            ->assertJsonPath('data.confirmation_status', 'to_confirm')->assertJsonPath('data.source', 'WhatsApp');
        $order = Order::findOrFail($res->json('data.id'));
        $this->assertNull($order->shopify_shop_id);
        $this->assertSame('0612345678', $order->phone);
        $this->assertSame('CMD-'.(1000 + $order->id), $res->json('data.reference'));
        $this->getJson('/api/orders?q=Montre')->assertJsonPath('meta.total', 1);
    }
}
