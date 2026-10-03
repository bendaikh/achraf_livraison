<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SpeedafSetting;
use App\Models\User;
use App\Services\Carriers\CarrierInterface;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** T11 — Commandes quick ship (generic carrier registry), compact list filters. */
class CarrierQuickShipTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected const UAT = 'https://uat-api.speedaf.com/open-api/';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signInAdmin();
    }

    protected function configureSpeedaf(): void
    {
        SpeedafSetting::forCompany($this->admin->resolveCompanyId())->fill([
            'enabled' => true, 'environment' => 'uat', 'app_code' => 'MA000025', 'customer_code' => 'MA000025',
            'platform_source' => 'TEST', 'sender_name' => 'Lavfast', 'sender_mobile' => '0522000000',
            'sender_address' => 'Bd Zerktouni', 'sender_city' => 'Casablanca',
        ])->save();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::UAT.'common/area/v2/getTreeByCountryCode*' => Http::response(['success' => true, 'error' => null, 'data' => ['success' => true, 'error' => null, 'data' => [
                'code' => 'MA', 'name' => 'Morocco', 'children' => [['code' => 'R1', 'name' => 'Casablanca - Settat', 'type' => 1, 'children' => [
                    ['code' => 'C1', 'name' => 'Casablanca', 'type' => 2, 'children' => [['code' => 'D1', 'name' => 'Maarif']]],
                ]]],
            ]]]),
            self::UAT.'express/order/v2/createOrder*' => Http::response(['success' => true, 'error' => null, 'data' => [
                'success' => true, 'billCode' => 'MA0200000999', 'labelUrl' => null,
            ]]),
        ]);
    }

    protected function confirmedOrder(array $data = []): Order
    {
        $order = $this->order($data + [
            'customer_name' => 'Fatima', 'customer_phone' => '0612345678', 'city' => 'Casablanca', 'address' => '12 Rue Atlas',
            'amount' => 250, 'product_name' => 'Robe', 'quantity' => 1, 'payment_method' => 'cod',
        ]);
        $order->forceFill(['confirmation_status' => 'confirmed', 'delivery_status' => 'to_assign'])->save();

        return $order->fresh();
    }

    public function test_carriers_endpoint_lists_registry_with_availability(): void
    {
        $res = $this->getJson('/api/carriers')->assertOk()
            ->assertJsonPath('carriers.0.key', 'speedaf')
            ->assertJsonPath('carriers.0.available', false)
            ->assertJsonPath('can_ship', true)
            ->assertJsonPath('can_assign_driver', true);
        $this->assertStringContainsString('Speedaf', $res->json('carriers.0.reason'));

        $this->configureSpeedaf();
        app()->forgetInstance(CarrierRegistry::class);
        $this->getJson('/api/carriers')->assertJsonPath('carriers.0.available', true)->assertJsonPath('carriers.0.reason', null);
    }

    public function test_quick_ship_single_order_to_speedaf_returns_generic_shipment(): void
    {
        $this->configureSpeedaf();
        $order = $this->confirmedOrder();

        $this->postJson('/api/carriers/speedaf/ship', ['order_ids' => [$order->id]])->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('results.0.tracking', 'MA0200000999')
            ->assertJsonPath('data.shipment.carrier', 'speedaf')
            ->assertJsonPath('data.shipment.carrier_label', 'Speedaf')
            ->assertJsonPath('data.shipment.tracking', 'MA0200000999')
            ->assertJsonPath('data.carrier', 'Speedaf');

        // Shown in the list, historised, filterable.
        $this->getJson('/api/orders')->assertJsonPath('data.0.shipment.tracking', 'MA0200000999');
        $this->assertTrue(collect($order->fresh()->confirmation_history)->contains(fn ($h) => ($h['code'] ?? $h['status'] ?? null) === 'speedaf_created' || str_contains((string) ($h['label'] ?? $h['note'] ?? ''), 'Speedaf')));
        $this->assertSame([$order->id], collect($this->getJson('/api/orders?carrier=speedaf')->json('data'))->pluck('id')->all());
        $this->assertSame([$order->id], collect($this->getJson('/api/orders?carrier=external')->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->getJson('/api/orders?carrier=none')->json('data'));

        // Second send is refused with a clear message.
        $this->postJson('/api/carriers/speedaf/ship', ['order_ids' => [$order->id]])->assertStatus(422)
            ->assertJsonPath('sent', 0);
    }

    public function test_unconfirmed_or_unavailable_carrier_is_refused(): void
    {
        $order = $this->order(['customer_name' => 'X', 'amount' => 100, 'product_name' => 'Sac']);
        $this->postJson('/api/carriers/speedaf/ship', ['order_ids' => [$order->id]])->assertStatus(422); // not configured
        $this->postJson('/api/carriers/unknown/ship', ['order_ids' => [$order->id]])->assertNotFound();

        $this->configureSpeedaf();
        $this->postJson('/api/carriers/speedaf/ship', ['order_ids' => [$order->id]])->assertStatus(422)
            ->assertJsonPath('results.0.success', false);
        $this->assertNull($order->fresh()->carrier);
    }

    public function test_ship_requires_permission(): void
    {
        $agent = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Setting::setValue('role_permissions', ['orders.ship' => []]);
        $this->actingAs($agent);
        $order = $this->confirmedOrder();
        $this->postJson('/api/carriers/speedaf/ship', ['order_ids' => [$order->id]])->assertForbidden();
        $this->getJson('/api/carriers')->assertJsonPath('can_ship', false);
    }

    public function test_registry_accepts_new_carriers_from_config(): void
    {
        $fake = new class implements CarrierInterface
        {
            public function key(): string { return 'sift'; }

            public function label(): string { return 'Sift'; }

            public function color(): string { return '#000000'; }

            public function unavailableReason(int $companyId): ?string { return null; }

            public function ship(int $companyId, iterable $orders, ?User $user = null): array
            {
                $out = [];
                foreach ($orders as $o) {
                    $o->forceFill(['carrier' => 'Sift'])->save();
                    $out[] = ['order_id' => $o->id, 'reference' => $o->reference(), 'success' => true, 'tracking' => 'SIFT-'.$o->id, 'message' => 'OK'];
                }

                return $out;
            }

            public function shipmentFor(Order $order): ?array
            {
                return $order->carrier === 'Sift' ? ['carrier' => 'sift', 'carrier_label' => 'Sift', 'color' => '#000000', 'tracking' => 'SIFT-'.$order->id] : null;
            }

            public function labels(int $companyId, iterable $orders): array { return []; }

            public function scopeShipped(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
            {
                return $query->where('carrier', 'Sift');
            }
        };
        app()->instance('test.sift', $fake);
        config(['carriers.drivers' => ['speedaf' => \App\Services\Carriers\SpeedafCarrier::class, 'sift' => 'test.sift']]);
        app()->forgetInstance(CarrierRegistry::class);

        $this->assertSame(['speedaf', 'sift'], array_column($this->getJson('/api/carriers')->json('carriers'), 'key'));
        $order = $this->confirmedOrder();
        $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$order->id]])->assertOk()->assertJsonPath('data.shipment.tracking', 'SIFT-'.$order->id);
        $this->assertSame([$order->id], collect($this->getJson('/api/orders?carrier=sift')->json('data'))->pluck('id')->all());
    }

    public function test_local_carrier_filter_and_compact_filters(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_LIVREUR]);
        $driver = Driver::create(['name' => 'Yassine', 'user_id' => $user->id, 'phone' => '0600000000', 'tariff_livraison' => 20, 'is_active' => true]);
        $local = $this->confirmedOrder(['customer_name' => 'Local', 'city' => 'Rabat']);
        $this->postJson('/api/local-delivery/assign', ['order_ids' => [$local->id], 'driver_id' => $driver->id])->assertOk();
        $paid = $this->confirmedOrder(['customer_name' => 'Payé', 'payment_method' => 'paye', 'city' => 'Tanger']);
        $paid->forceFill(['assigned_user_id' => $this->admin->id])->save();

        $ids = fn (string $qs) => collect($this->getJson('/api/orders?'.$qs)->json('data'))->pluck('id')->all();
        $this->assertSame([$local->id], $ids('carrier=local'));
        $this->assertSame([$paid->id], $ids('carrier=none'));
        $this->assertSame([$paid->id], $ids('payment_method=paye'));
        $this->assertSame([$local->id], $ids('payment_method=cod'));
        $this->assertSame([$local->id], $ids('city=rab'));
        $this->assertSame([$paid->id], $ids('assigned_user_id='.$this->admin->id));
        $this->assertCount(2, $ids('period=today'));
        $this->assertCount(0, $ids('period=yesterday'));
        $this->getJson('/api/orders?carrier=local')->assertJsonPath('data.0.driver.name', 'Yassine')->assertJsonPath('data.0.shipment', null);
    }
}
