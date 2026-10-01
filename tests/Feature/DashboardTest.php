<?php

namespace Tests\Feature;

use App\Models\DeliveryStatus;
use App\Models\Driver;
use App\Models\Mission;
use App\Models\Order;
use App\Services\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    protected function st(string $code): DeliveryStatus
    {
        return DeliveryStatus::where('code', $code)->firstOrFail();
    }

    public function test_empty_database_returns_zeros_and_no_rates(): void
    {
        $res = $this->getJson('/api/dashboard')->assertOk();
        foreach ($res->json('data.cards') as $value) {
            $this->assertSame(0, $value);
        }
        $this->assertNull($res->json('data.rates.confirmation'));
        $this->assertNull($res->json('data.rates.delivery'));
        $this->assertSame(0, $res->json('data.cash.cod_collected'));
        $res->assertJsonPath('data.period.key', 'today');
    }

    public function test_cards_are_computed_from_real_orders_and_status_categories(): void
    {
        $wf = app(OrderWorkflow::class);
        $yassine = Driver::create(['name' => 'Yassine', 'tariff_livraison' => 20]);
        $achraf = Driver::create(['name' => 'Achraf', 'tariff_livraison' => 25]);

        $this->order(['customer_name' => 'A', 'amount' => 100]);                         // à confirmer
        $wf->changeConfirmation($this->order(['customer_name' => 'B', 'amount' => 100]), 'no_answer');
        $wf->changeConfirmation($this->order(['customer_name' => 'C', 'amount' => 100]), 'confirmed'); // à attribuer

        $d = $this->order(['customer_name' => 'D', 'amount' => 250]);
        $wf->changeConfirmation($d, 'confirmed');
        $wf->assignDriver($d, $yassine->id);
        $wf->changeStatus($d, $this->st('in_progress'));
        $wf->changeStatus($d, $this->st('delivered'), ['collected_amount' => 250]);

        $e = $this->order(['customer_name' => 'E', 'amount' => 80]);
        $wf->changeConfirmation($e, 'confirmed');
        $wf->assignDriver($e, $achraf->id);
        $wf->changeStatus($e, $this->st('in_progress'));

        $f = $this->order(['customer_name' => 'F', 'amount' => 90]);
        $wf->changeConfirmation($f, 'confirmed');
        $wf->assignDriver($f, $achraf->id);
        $wf->changeStatus($f, $this->st('failed'), ['reason' => 'Absent']);

        // An old order outside the period must not be counted.
        Carbon::setTestNow(now()->subDays(10));
        $this->order(['customer_name' => 'Old', 'amount' => 999]);
        Carbon::setTestNow();

        $res = $this->getJson('/api/dashboard?period=today')->assertOk();
        $res->assertJsonPath('data.cards.received', 6)
            ->assertJsonPath('data.cards.to_confirm', 1)
            ->assertJsonPath('data.cards.confirmed', 4)
            ->assertJsonPath('data.cards.no_answer', 1)
            ->assertJsonPath('data.cards.to_assign', 1)
            ->assertJsonPath('data.cards.in_delivery', 1)
            ->assertJsonPath('data.cards.delivered', 1)
            ->assertJsonPath('data.rates.confirmation.value', 80)   // 4 / 5 processed
            ->assertJsonPath('data.rates.delivery.denominator', 3)  // livrée + en cours + échouée
            ->assertJsonPath('data.cash.cod_collected', 250)
            ->assertJsonPath('data.cash.cod_with_drivers', 250)
            ->assertJsonPath('data.cash.commissions_earned', 20); // separate from COD

        $this->getJson('/api/dashboard?period=month')->assertJsonPath('data.cards.received', now()->day > 10 ? 7 : 6);

        // Driver filter
        $res = $this->getJson('/api/dashboard?driver_id='.$achraf->id)->assertOk();
        $res->assertJsonPath('data.cards.in_delivery', 1)->assertJsonPath('data.cards.delivered', 0);
        $this->assertCount(1, $res->json('data.drivers'));
        $res->assertJsonPath('data.drivers.0.failed', 1)->assertJsonPath('data.drivers.0.assigned', 2);

        // A status created later in the same category is counted automatically.
        $custom = DeliveryStatus::create(['name' => 'Livrée partiellement', 'code' => 'livree_partielle', 'color' => '#22c55e', 'category' => 'succes']);
        $wf->changeStatus($e, $custom, ['collected_amount' => 40]);
        $this->getJson('/api/dashboard')->assertJsonPath('data.cards.delivered', 2)->assertJsonPath('data.cash.cod_collected', 290);
    }

    public function test_custom_period_and_yesterday(): void
    {
        Carbon::setTestNow(now()->subDay()->setTime(10, 0));
        $this->order(['customer_name' => 'Hier', 'amount' => 10]);
        Carbon::setTestNow();
        $this->getJson('/api/dashboard?period=yesterday')->assertJsonPath('data.cards.received', 1);
        $this->getJson('/api/dashboard?period=today')->assertJsonPath('data.cards.received', 0);
        $day = now()->subDay()->toDateString();
        $this->getJson("/api/dashboard?period=custom&from={$day}&to={$day}")->assertJsonPath('data.cards.received', 1);
    }

    public function test_create_ramassage_and_depot_partenaire_missions_with_price_snapshot(): void
    {
        $driver = Driver::create(['name' => 'Yassine', 'tariff_ramassage' => 10, 'tariff_depot_partenaire' => 6]);
        $today = now()->toDateString();

        $this->postJson('/api/missions', [
            'type' => 'ramassage', 'contact_name' => 'Boutique Zahra', 'phone' => '0600000000', 'address' => 'Bd Anfa',
            'city' => 'Casablanca', 'items_description' => 'Colis', 'quantity' => 3, 'scheduled_date' => $today,
            'time_slot' => '10:00 - 12:00', 'driver_id' => $driver->id, 'note' => 'Sonner 2 fois', 'cash_amount' => 150, 'cash_direction' => 'collect',
        ])->assertCreated()->assertJsonPath('data.type', 'ramassage')->assertJsonPath('data.driver_price', 10)
            ->assertJsonPath('data.driver.name', 'Yassine');

        $depot = $this->postJson('/api/missions', [
            'type' => 'depot_partenaire', 'contact_name' => 'Agence Ozone', 'items_description' => 'Colis', 'quantity' => 12,
            'scheduled_date' => $today, 'driver_id' => $driver->id,
        ])->assertCreated()->assertJsonPath('data.driver_price', 6)->json('data');

        $this->postJson('/api/missions', ['type' => 'ramassage'])->assertStatus(422)->assertJsonValidationErrors('contact_name');

        // Visible in the driver's missions, with history, and counted in the dashboard.
        $this->getJson('/api/missions?driver_id='.$driver->id)->assertJsonCount(2, 'data');
        $this->getJson('/api/missions/'.$depot['id'])->assertJsonPath('data.histories.1.event', 'created');
        $this->getJson('/api/dashboard')->assertJsonPath('data.missions.pickups_todo', 1)->assertJsonPath('data.missions.deposits_todo', 1);

        $this->postJson('/api/missions/'.$depot['id'].'/status', ['status' => 'terminee'])->assertOk();
        $this->putJson("/api/drivers/{$driver->id}", ['tariff_depot_partenaire' => 9])->assertOk();
        $this->getJson('/api/dashboard')
            ->assertJsonPath('data.missions.deposits_todo', 0)
            ->assertJsonPath('data.missions.completed', 1)
            ->assertJsonPath('data.drivers.0.earnings', 6); // snapshot, not the new 9 DH
    }

    public function test_closing_freezes_cod_and_commissions_and_feeds_dashboard(): void
    {
        $wf = app(OrderWorkflow::class);
        $driver = Driver::create(['name' => 'Y', 'tariff_livraison' => 20]);
        $o = $this->order(['customer_name' => 'A', 'amount' => 300]);
        $wf->changeConfirmation($o, 'confirmed');
        $wf->assignDriver($o, $driver->id);
        $wf->changeStatus($o, $this->st('delivered'), ['collected_amount' => 300]);

        $this->getJson('/api/closings/pending')->assertJsonPath('data.0.cod', 300)->assertJsonPath('data.0.commissions', 20);
        $this->postJson('/api/closings', ['driver_id' => $driver->id, 'cod_remitted' => 280])->assertCreated()
            ->assertJsonPath('data.gap', 20)->assertJsonPath('data.commissions_total', 20);

        $this->getJson('/api/dashboard')
            ->assertJsonPath('data.cash.cod_with_drivers', 0)
            ->assertJsonPath('data.cash.closed_amount', 280)
            ->assertJsonPath('data.cash.remaining_to_remit', 20);

        // Closed mission is locked: tariff and status cannot change anymore.
        $mission = Mission::where('order_id', $o->id)->first();
        $this->assertNotNull($mission->closing_id);
        $this->postJson("/api/missions/{$mission->id}/status", ['status' => 'annulee'])->assertStatus(422);
        $this->postJson('/api/closings', ['driver_id' => $driver->id])->assertStatus(422);
    }

    public function test_alerts_zone(): void
    {
        Carbon::setTestNow(now()->subDays(2));
        $this->order(['customer_name' => 'Ancienne', 'amount' => 10]);
        app(\App\Services\MissionService::class)->create(['type' => 'ramassage', 'contact_name' => 'X', 'scheduled_date' => now()->toDateString()]);
        Carbon::setTestNow();

        $alerts = collect($this->getJson('/api/dashboard')->json('data.alerts'))->keyBy('key');
        $this->assertSame(1, $alerts['stale_confirmations']['count']);
        $this->assertSame(1, $alerts['late_missions']['count']);
    }
}
