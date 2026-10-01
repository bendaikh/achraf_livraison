<?php

namespace Tests\Feature;

use App\Models\DeliveryStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function statusId(string $code): int
    {
        return DeliveryStatus::where('code', $code)->value('id');
    }

    public function test_ten_default_statuses_are_seeded_with_categories_and_transitions(): void
    {
        $res = $this->getJson('/api/delivery-statuses')->assertOk();
        $this->assertCount(10, $res->json('data'));
        $this->assertEquals(
            ['a_attribuer', 'attribuee', 'en_cours', 'livree', 'pas_de_reponse', 'reportee', 'echouee', 'annulee', 'retour', 'echange'],
            collect($res->json('data'))->pluck('code')->all()
        );
        $this->assertDatabaseCount('status_transitions', 23);
    }

    public function test_new_status_appears_dynamically_in_meta_and_can_be_used(): void
    {
        $res = $this->postJson('/api/delivery-statuses', [
            'name' => 'Client absent', 'color' => '#123456', 'category' => 'injoignable', 'icon' => 'user-x',
        ])->assertCreated()->assertJsonPath('data.code', 'client_absent');
        $id = $res->json('data.id');

        $this->assertContains('Client absent', collect($this->getJson('/api/meta')->json('statuses'))->pluck('name'));

        $order = Order::create(['customer_name' => 'C', 'amount' => 100]);
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $id])->assertOk()
            ->assertJsonPath('data.delivery_status.name', 'Client absent');
        $this->getJson('/api/orders?delivery_status_id='.$id)->assertJsonCount(1, 'data');
    }

    public function test_code_must_be_unique(): void
    {
        $this->postJson('/api/delivery-statuses', ['name' => 'X', 'code' => 'livree', 'color' => '#000000', 'category' => 'succes'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_used_status_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $order = Order::create(['customer_name' => 'C', 'amount' => 100]);
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('en_cours')])->assertOk();

        $id = $this->statusId('en_cours');
        $this->deleteJson("/api/delivery-statuses/{$id}")->assertStatus(409);
        $this->assertDatabaseHas('delivery_statuses', ['id' => $id]);

        $this->putJson("/api/delivery-statuses/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertNotContains($id, collect($this->getJson('/api/meta')->json('statuses'))->pluck('id'));
        // Existing order still shows its (inactive) status.
        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.delivery_status.id', $id);
        // Inactive status cannot be applied anymore.
        $order2 = Order::create(['customer_name' => 'D', 'amount' => 100]);
        $this->postJson("/api/orders/{$order2->id}/status", ['delivery_status_id' => $id])->assertStatus(422);
    }

    public function test_unused_status_can_be_deleted(): void
    {
        $id = $this->postJson('/api/delivery-statuses', ['name' => 'À reprogrammer', 'color' => '#abcdef', 'category' => 'report'])->json('data.id');
        $this->deleteJson("/api/delivery-statuses/{$id}")->assertNoContent();
    }

    public function test_required_fields_are_enforced_on_status_change(): void
    {
        $order = Order::create(['customer_name' => 'C', 'amount' => 150]);

        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('reportee')])
            ->assertStatus(422)->assertJsonValidationErrors('postponed_date');
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('reportee'), 'postponed_date' => '2026-10-05'])
            ->assertStatus(422)->assertJsonValidationErrors('postponed_time');
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('reportee'), 'postponed_date' => '2026-10-05', 'postponed_time' => '14:30'])
            ->assertOk()->assertJsonPath('data.postponed_at', '2026-10-05 14:30');

        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('echouee')])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('annulee')])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('livree')])
            ->assertStatus(422)->assertJsonValidationErrors('collected_amount');
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('livree'), 'collected_amount' => 150])
            ->assertOk()->assertJsonPath('data.collected_amount', 150);

        // Configuration is evolutive: removing the requirement makes the field optional.
        $this->putJson('/api/delivery-statuses/'.$this->statusId('echouee'), ['required_fields' => []])->assertOk();
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('echouee')])->assertOk();
    }

    public function test_history_keeps_snapshot_after_rename(): void
    {
        $order = Order::create(['customer_name' => 'C', 'amount' => 100]);
        $id = $this->statusId('en_cours');
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $id])->assertOk();

        $this->putJson("/api/delivery-statuses/{$id}", ['name' => 'En route', 'color' => '#000000'])->assertOk();

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id, 'delivery_status_id' => $id, 'status_name' => 'En cours', 'status_code' => 'en_cours', 'status_color' => '#2563eb',
        ]);
        $this->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('data.histories.0.status_name', 'En cours')
            ->assertJsonPath('data.delivery_status.name', 'En route');
    }

    public function test_transitions_are_enforced_when_enabled(): void
    {
        $order = Order::create(['customer_name' => 'C', 'amount' => 100]);
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('a_attribuer')])->assertOk();

        // Off by default: any active status is allowed.
        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.allowed_status_ids', null);

        Setting::setValue('enforce_status_transitions', true);
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('livree'), 'collected_amount' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('delivery_status_id');
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('attribuee')])->assertOk();

        // Admin can edit transitions.
        $this->putJson('/api/delivery-statuses/'.$this->statusId('attribuee').'/transitions', ['to_status_ids' => [$this->statusId('livree')]])->assertOk();
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('livree'), 'collected_amount' => 100])->assertOk();
    }

    public function test_status_category_drives_missions(): void
    {
        $driver = Driver::create(['name' => 'Y', 'tariff_livraison' => 20, 'tariff_retour' => 7]);
        $order = Order::create(['customer_name' => 'C', 'amount' => 100]);
        $this->postJson("/api/orders/{$order->id}/confirmation", ['confirmation_status' => 'confirmee'])
            ->assertOk()->assertJsonPath('data.delivery_status.code', 'a_attribuer');
        $this->putJson("/api/orders/{$order->id}", ['driver_id' => $driver->id])->assertOk()->assertJsonPath('data.delivery_status.code', 'attribuee');
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $this->statusId('retour')])->assertOk();

        $this->assertDatabaseHas('missions', ['order_id' => $order->id, 'type' => 'livraison', 'status' => 'echouee']);
        $this->assertDatabaseHas('missions', ['order_id' => $order->id, 'type' => 'retour', 'status' => 'a_faire', 'driver_price' => 7]);
        $this->assertSame(4, $order->histories()->count()); // confirmation + à attribuer + attribuée + retour
    }
}
