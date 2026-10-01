<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Mission;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverTariffTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_company_default_tariffs_prefill_new_driver(): void
    {
        $this->putJson('/api/settings', ['default_tariffs' => [
            'livraison' => 22, 'ramassage' => 11, 'depot_partenaire' => 5, 'retour' => 8, 'echange' => 9,
        ]])->assertOk()->assertJsonPath('data.default_tariffs.echange', 9);

        $this->getJson('/api/meta')->assertJsonPath('default_tariffs.livraison', 22);

        $res = $this->postJson('/api/drivers', ['name' => 'Nouveau livreur', 'email' => 'nouveau@demo.test', 'password' => 'secret123'])->assertCreated();
        $res->assertJsonPath('data.tariffs.livraison', 22)
            ->assertJsonPath('data.tariffs.ramassage', 11)
            ->assertJsonPath('data.tariffs.depot_partenaire', 5)
            ->assertJsonPath('data.tariffs.retour', 8)
            ->assertJsonPath('data.tariffs.echange', 9);
    }

    public function test_admin_can_override_defaults_per_driver_with_separate_retour_and_echange(): void
    {
        $res = $this->postJson('/api/drivers', [
            'name' => 'Yassine', 'email' => 'yassine@demo.test', 'password' => 'secret123',
            'tariff_livraison' => 20, 'tariff_ramassage' => 10, 'tariff_depot_partenaire' => 6,
            'tariff_retour' => 7, 'tariff_echange' => 12,
        ])->assertCreated();

        $id = $res->json('data.id');
        $this->putJson("/api/drivers/{$id}", ['tariff_echange' => 7.5])->assertOk()
            ->assertJsonPath('data.tariffs.echange', 7.5)
            ->assertJsonPath('data.tariffs.retour', 7);
    }

    public function test_tariff_snapshot_is_not_retroactive(): void
    {
        $driver = Driver::create(['name' => 'Yassine', 'tariff_livraison' => 20, 'tariff_ramassage' => 10]);
        $order = $this->order(['customer_name' => 'Client', 'amount' => 199]);

        // Assigning the order creates the livraison mission with today's tariff (20 DH).
        $this->putJson("/api/orders/{$order->id}", ['driver_id' => $driver->id])->assertOk();
        $mission = Mission::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('livraison', $mission->type);
        $this->assertEquals(20, (float) $mission->driver_price);
        $this->assertNotNull($mission->assigned_at);

        $this->postJson("/api/missions/{$mission->id}/status", ['status' => 'terminee'])->assertOk();

        // Tomorrow the tariff goes up to 25 DH…
        $this->putJson("/api/drivers/{$driver->id}", ['tariff_livraison' => 25])->assertOk();

        // …the past mission stays at 20 DH, new missions use 25 DH.
        $this->assertEquals(20, (float) $mission->fresh()->driver_price);
        $order2 = $this->order(['customer_name' => 'Client 2', 'amount' => 99]);
        $this->putJson("/api/orders/{$order2->id}", ['driver_id' => $driver->id])->assertOk();
        $this->assertEquals(25, (float) Mission::where('order_id', $order2->id)->value('driver_price'));
    }

    public function test_completed_mission_cannot_be_reassigned(): void
    {
        $a = Driver::create(['name' => 'A', 'tariff_ramassage' => 10]);
        $b = Driver::create(['name' => 'B', 'tariff_ramassage' => 15]);
        $id = $this->postJson('/api/missions', ['type' => 'ramassage', 'contact_name' => 'X', 'driver_id' => $a->id])
            ->assertCreated()->assertJsonPath('data.driver_price', 10)->json('data.id');
        $this->postJson("/api/missions/{$id}/status", ['status' => 'terminee'])->assertOk();
        $this->putJson("/api/missions/{$id}", ['driver_id' => $b->id])->assertStatus(422);
        $this->assertEquals(10, (float) Mission::find($id)->driver_price);
    }

    public function test_reassigning_open_mission_snapshots_new_driver_tariff(): void
    {
        $a = Driver::create(['name' => 'A', 'tariff_depot_partenaire' => 6]);
        $b = Driver::create(['name' => 'B', 'tariff_depot_partenaire' => 9]);
        $id = $this->postJson('/api/missions', ['type' => 'depot_partenaire', 'contact_name' => 'Partenaire', 'driver_id' => $a->id])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/missions/{$id}", ['driver_id' => $b->id])->assertOk()->assertJsonPath('data.driver_price', 9);
    }

    public function test_column_preferences_are_stored_per_user(): void
    {
        $value = ['desktop' => ['reference', 'customer', 'amount'], 'mobile' => ['customer', 'status']];
        $this->putJson('/api/preferences/orders.columns', ['value' => $value])->assertOk()->assertJsonPath('user_id', 1);
        $this->getJson('/api/preferences/orders.columns')->assertOk()->assertJsonPath('value.mobile.1', 'status');
        $this->assertDatabaseHas('user_preferences', ['user_id' => 1, 'key' => 'orders.columns']);
    }
}
