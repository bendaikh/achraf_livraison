<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Mission;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\SpeedafShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T2 — Commandes → « Affecter à livraison locale » (bulk + single, re-assignment, permissions). */
class LocalAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function driver(string $name, array $extra = []): Driver
    {
        $user = User::factory()->create(['name' => $name, 'role' => User::ROLE_LIVREUR]);

        return Driver::create($extra + ['name' => $name, 'user_id' => $user->id, 'phone' => '0600000000', 'tariff_livraison' => 20, 'is_active' => true]);
    }

    protected function confirmedOrder(array $data = []): Order
    {
        $order = $this->order($data + ['customer_name' => 'Client', 'amount' => 199, 'city' => 'Casablanca', 'product_name' => 'Sac']);
        $this->postJson("/api/orders/{$order->id}/confirmation", ['confirmation_status' => 'confirmed'])->assertOk();

        return $order->fresh();
    }

    public function test_drivers_drawer_lists_active_drivers_with_load_and_cash(): void
    {
        $this->signInAdmin();
        $yassine = $this->driver('Yassine');
        $this->driver('Inactif', ['is_active' => false]);
        $order = $this->confirmedOrder();
        $this->postJson('/api/local-delivery/assign', ['order_ids' => [$order->id], 'driver_id' => $yassine->id])->assertOk();

        $res = $this->getJson('/api/local-delivery/drivers')->assertOk();
        $this->assertCount(1, $res->json('drivers'));
        $res->assertJsonPath('drivers.0.name', 'Yassine')
            ->assertJsonPath('drivers.0.missions_in_progress', 1)
            ->assertJsonPath('drivers.0.cod_held', 0);
    }

    public function test_bulk_assignment_moves_orders_to_driver_with_status_history_and_driver_space(): void
    {
        $admin = $this->signInAdmin();
        $yassine = $this->driver('Yassine');
        $a = $this->confirmedOrder(['customer_name' => 'A']);
        $b = $this->confirmedOrder(['customer_name' => 'B']);
        $unconfirmed = $this->order(['customer_name' => 'C', 'amount' => 100]);
        $ordersBefore = Order::count();

        $res = $this->postJson('/api/local-delivery/assign', ['order_ids' => [$a->id, $b->id, $unconfirmed->id], 'driver_id' => $yassine->id])
            ->assertOk()
            ->assertJsonPath('assigned_count', 2)
            ->assertJsonPath('failed_count', 1);
        $this->assertFalse($res->json('results.2.success'));
        $this->assertSame('Commande non confirmée.', $res->json('results.2.message'));

        foreach ([$a, $b] as $order) {
            $order->refresh();
            $this->assertSame($yassine->id, $order->driver_id);
            $this->assertSame('assigned', $order->delivery_status); // « Attribuée » (status_on_assign)
            $this->assertSame($admin->id, $order->assigned_by);
            $this->assertNotNull($order->assigned_at);
            $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'kind' => 'affectation', 'status_code' => 'driver_assigned', 'user_id' => $admin->id]);
            $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'kind' => 'livraison', 'status_code' => 'assigned']);
            $this->assertSame('delivery_assigned', collect($order->confirmation_history)->last()['type']);
        }
        $this->assertSame($ordersBefore, Order::count(), 'No copy of the orders is created');
        $this->assertNull($unconfirmed->fresh()->driver_id);

        // Immediately in the driver's space.
        $this->actingAs($yassine->user)->getJson('/api/driver/missions')->assertOk()->assertJsonCount(2, 'orders');

        // Commandes filter by driver.
        $this->actingAs($admin)->getJson('/api/orders?driver_id='.$yassine->id)->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.driver.name', 'Yassine');
    }

    public function test_reassignment_is_historised_from_old_to_new_driver(): void
    {
        $admin = $this->signInAdmin();
        $yassine = $this->driver('Yassine');
        $achraf = $this->driver('Achraf', ['tariff_livraison' => 25]);
        $order = $this->confirmedOrder();

        $this->postJson('/api/local-delivery/assign', ['order_ids' => [$order->id], 'driver_id' => $yassine->id])->assertOk();
        // Same driver again → reported, nothing changes.
        $this->postJson('/api/local-delivery/assign', ['order_ids' => [$order->id], 'driver_id' => $yassine->id])
            ->assertStatus(422)->assertJsonPath('results.0.message', 'Déjà affectée à ce livreur.');

        // Driver already took it (en livraison) → re-assign from the order page.
        $this->actingAs($yassine->user)->postJson("/api/driver/missions/{$order->id}/postpone", ['postpone_at' => now()->addDay()->format('Y-m-d H:i'), 'reason' => 'Client absent'])->assertOk();
        $this->actingAs($admin)->postJson('/api/local-delivery/assign', ['order_ids' => [$order->id], 'driver_id' => $achraf->id])
            ->assertOk()->assertJsonPath('results.0.message', 'Réaffectée : Yassine → Achraf');

        $order->refresh();
        $this->assertSame($achraf->id, $order->driver_id);
        $this->assertSame('assigned', $order->delivery_status);
        $h = OrderStatusHistory::where('order_id', $order->id)->where('status_code', 'driver_reassigned')->firstOrFail();
        $this->assertSame('Yassine → Achraf', $h->note);
        $this->assertSame('Yassine', $h->data['from_driver_name']);
        $this->assertSame($admin->id, $h->user_id);
        $this->assertEquals(25, (float) Mission::where('order_id', $order->id)->where('type', 'livraison')->latest('id')->value('driver_price'));
        $this->actingAs($yassine->user)->getJson('/api/driver/missions')->assertJsonCount(0, 'orders');
        $this->actingAs($achraf->user)->getJson('/api/driver/missions')->assertJsonCount(1, 'orders');

        // Shown in the order history payload.
        $this->actingAs($admin)->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonFragment(['kind' => 'affectation', 'note' => 'Yassine → Achraf']);
    }

    public function test_orders_sent_to_speedaf_or_delivered_are_refused(): void
    {
        $this->signInAdmin();
        $yassine = $this->driver('Yassine');
        $order = $this->confirmedOrder();
        SpeedafShipment::create(['order_id' => $order->id, 'bill_code' => 'SP1', 'state' => 'created', 'environment' => 'test']);

        $this->postJson('/api/local-delivery/assign', ['order_ids' => [$order->id], 'driver_id' => $yassine->id])
            ->assertStatus(422)->assertJsonPath('failed_count', 1);
        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_only_authorised_users_can_assign(): void
    {
        $yassine = $this->driver('Yassine');
        $order = $this->confirmedOrderAsAdmin();
        $agent = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($agent)->postJson('/api/local-delivery/assign', ['order_ids' => [$order->id], 'driver_id' => $yassine->id])->assertForbidden();
        $this->actingAs($agent)->putJson("/api/orders/{$order->id}", ['driver_id' => $yassine->id])->assertForbidden();
        $this->actingAs($agent)->getJson('/user')->assertJsonMissing(['orders.assign_driver']);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->getJson('/user')->assertJsonFragment(['orders.assign_driver']);
        $this->actingAs($admin)->postJson('/api/local-delivery/assign', ['order_ids' => [$order->id], 'driver_id' => $yassine->id])->assertOk();
    }

    protected function confirmedOrderAsAdmin(): Order
    {
        $this->signInAdmin();

        return $this->confirmedOrder();
    }
}
