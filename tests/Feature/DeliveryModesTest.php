<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OzonShipment;
use App\Models\Setting;
use App\Models\SiftShipment;
use App\Models\SpeedafSetting;
use App\Models\SpeedafShipment;
use App\Models\User;
use App\Services\Carriers\CarrierInterface;
use App\Services\Carriers\CarrierPresentation;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\OzonCarrier;
use App\Services\Carriers\SiftCarrier;
use App\Services\Carriers\SpeedafCarrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Phase 2 — shared delivery modes, local pre-check, partial bulk send. */
class DeliveryModesTest extends TestCase
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

    protected function configureSpeedaf(?int $companyId = null): void
    {
        SpeedafSetting::forCompany($companyId ?: $this->admin->resolveCompanyId())->fill([
            'enabled' => true, 'environment' => 'uat', 'app_code' => 'MA000025', 'customer_code' => 'MA000025',
            'platform_source' => 'TEST', 'sender_name' => 'Lavfast', 'sender_mobile' => '0522000000',
            'sender_address' => 'Bd Zerktouni', 'sender_city' => 'Casablanca',
        ])->save();
    }

    protected function readyOrder(array $data = []): Order
    {
        $order = $this->order($data + [
            'customer_name' => 'Fatima', 'customer_phone' => '0612345678', 'city' => 'Casablanca', 'address' => '12 Rue Atlas',
            'amount' => 250, 'product_name' => 'Robe', 'quantity' => 1, 'payment_method' => 'cod',
        ]);
        $order->forceFill(['confirmation_status' => 'confirmed', 'delivery_status' => 'to_assign'])->save();

        return $order->fresh();
    }

    public function test_delivery_modes_are_company_scoped_and_include_local(): void
    {
        $this->configureSpeedaf();
        app()->forgetInstance(CarrierRegistry::class);

        $modes = collect($this->getJson('/api/delivery-modes')->assertOk()
            ->assertJsonPath('can_ship', true)
            ->assertJsonPath('can_assign_driver', true)
            ->assertJsonPath('can_cancel', false)
            ->assertJsonPath('modes.0.key', 'local')
            ->assertJsonPath('modes.0.type', 'local')
            ->assertJsonPath('modes.0.label', 'Livraison locale')
            ->json('modes'));

        $this->assertTrue($modes->firstWhere('key', 'speedaf')['available']);
        $this->assertSame('/images/carriers/speedaf.svg', $modes->firstWhere('key', 'speedaf')['logo']);
        $this->assertFalse($modes->firstWhere('key', 'ozon')['available']);
        $this->assertFalse($modes->firstWhere('key', 'sift')['available']);
        $this->assertNotEmpty($modes->firstWhere('key', 'ozon')['reason']);

        $other = Company::create(['name' => 'Autre livraison', 'slug' => 'autre-livraison', 'is_active' => true]);
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN, 'company_id' => $other->id]);
        $this->actingAs($otherAdmin);
        $foreign = collect($this->getJson('/api/delivery-modes')->assertOk()->json('modes'));
        $this->assertFalse($foreign->firstWhere('key', 'speedaf')['available']);
        $this->assertSame('Livraison locale', $foreign->firstWhere('type', 'local')['label']);
        $this->assertNotSame($this->admin->resolveCompanyId(), $otherAdmin->resolveCompanyId());
    }

    public function test_a_carrier_registered_in_config_appears_with_logo_and_actions(): void
    {
        $fake = new class implements CarrierInterface, CarrierPresentation
        {
            public function key(): string
            {
                return 'fake';
            }

            public function label(): string
            {
                return 'Fake Express';
            }

            public function color(): string
            {
                return '#111827';
            }

            public function unavailableReason(int $companyId): ?string
            {
                return null;
            }

            public function ship(int $companyId, iterable $orders, ?User $user = null): array
            {
                $out = [];
                $i = 0;
                foreach ($orders as $order) {
                    $i++;
                    $fail = $i === 1;
                    $out[] = [
                        'order_id' => $order->id,
                        'reference' => $order->reference(),
                        'success' => ! $fail,
                        'tracking' => $fail ? null : 'FK'.$order->id,
                        'message' => $fail ? 'Refus transporteur (test).' : 'OK',
                    ];
                }

                return $out;
            }

            public function shipmentFor(Order $order): ?array
            {
                return null;
            }

            public function labels(int $companyId, iterable $orders): array
            {
                return [];
            }

            public function scopeShipped(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
            {
                return $query->whereRaw('1 = 0');
            }

            public function logoUrl(): ?string
            {
                return '/images/carriers/fake.svg';
            }

            public function actions(): array
            {
                return [['key' => 'track', 'label' => 'Suivre', 'method' => 'POST', 'url' => '/api/fake/orders/{id}/track']];
            }

            public function documents(): array
            {
                return [];
            }

            public function history(Order $order): array
            {
                return [];
            }

            public function recentCount(int $companyId, \DateTimeInterface $since): int
            {
                return 0;
            }
        };

        app()->instance('test.fake.carrier', $fake);
        config(['carriers.drivers' => [
            'speedaf' => SpeedafCarrier::class,
            'ozon' => OzonCarrier::class,
            'sift' => SiftCarrier::class,
            'fake' => 'test.fake.carrier',
        ]]);
        app()->forgetInstance(CarrierRegistry::class);

        $mode = collect($this->getJson('/api/delivery-modes')->assertOk()->json('modes'))->firstWhere('key', 'fake');
        $this->assertNotNull($mode);
        $this->assertSame('carrier', $mode['type']);
        $this->assertSame('Fake Express', $mode['label']);
        $this->assertSame('/images/carriers/fake.svg', $mode['logo']);
        $this->assertSame('Suivre', $mode['actions'][0]['label']);
        $this->assertTrue($mode['available']);

        config(['carriers.drivers' => [
            'speedaf' => SpeedafCarrier::class,
            'ozon' => OzonCarrier::class,
            'sift' => SiftCarrier::class,
        ]]);
        app()->forgetInstance(CarrierRegistry::class);
    }

    public function test_precheck_flags_three_problems_and_does_not_call_the_carrier(): void
    {
        $this->configureSpeedaf();
        Http::fake();
        $ids = [];
        foreach (range(1, 22) as $i) {
            $ids[] = $this->readyOrder(['customer_name' => 'Client '.$i])->id;
        }
        $shipped = $this->readyOrder(['customer_name' => 'Déjà partie']);
        OzonShipment::create([
            'company_id' => $shipped->company_id,
            'order_id' => $shipped->id,
            'tracking_number' => 'OZE123456',
            'state' => OzonShipment::STATE_CREATED,
            'raw_status' => 'Pris en charge',
        ]);
        $noPhone = $this->readyOrder(['customer_name' => 'Sans téléphone']);
        $noPhone->forceFill(['phone' => null])->save();
        $cancelled = $this->readyOrder(['customer_name' => 'Annulée']);
        $cancelled->forceFill(['delivery_status' => 'cancelled'])->save();

        $res = $this->postJson('/api/delivery-modes/speedaf/check', [
            'order_ids' => array_merge($ids, [$shipped->id, $noPhone->id, $cancelled->id]),
        ])->assertOk();

        Http::assertNothingSent();
        $res->assertJsonPath('selected', 25)->assertJsonPath('ready', 22)->assertJsonPath('problems', 3);
        $rows = collect($res->json('rows'))->keyBy('order_id');
        $this->assertSame('Déjà envoyée à Ozon Express (n° OZE123456).', $rows[$shipped->id]['reason']);
        $this->assertSame('Téléphone manquant.', $rows[$noPhone->id]['reason']);
        $this->assertSame('Commande au statut « Annulée » : envoi impossible.', $rows[$cancelled->id]['reason']);
        $this->assertFalse($rows[$shipped->id]['can_send']);
        $this->assertTrue($rows[$ids[0]]['can_send']);
    }

    public function test_one_carrier_failure_does_not_block_the_other_orders(): void
    {
        $fake = new class implements CarrierInterface
        {
            public function key(): string
            {
                return 'fake';
            }

            public function label(): string
            {
                return 'Fake Express';
            }

            public function color(): string
            {
                return '#111827';
            }

            public function unavailableReason(int $companyId): ?string
            {
                return null;
            }

            public function ship(int $companyId, iterable $orders, ?User $user = null): array
            {
                $out = [];
                $i = 0;
                foreach ($orders as $order) {
                    $i++;
                    $fail = $i === 1;
                    $out[] = [
                        'order_id' => $order->id, 'reference' => $order->reference(), 'success' => ! $fail,
                        'tracking' => $fail ? null : 'FK'.$order->id, 'message' => $fail ? 'Refus transporteur (test).' : 'OK',
                    ];
                }

                return $out;
            }

            public function shipmentFor(Order $order): ?array
            {
                return null;
            }

            public function labels(int $companyId, iterable $orders): array
            {
                return [];
            }

            public function scopeShipped(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
            {
                return $query->whereRaw('1 = 0');
            }
        };
        app()->instance('test.fake.ship', $fake);
        config(['carriers.drivers' => ['fake' => 'test.fake.ship'] + config('carriers.drivers')]);
        app()->forgetInstance(CarrierRegistry::class);
        Http::fake();

        $ids = [];
        foreach (range(1, 22) as $i) {
            $ids[] = $this->readyOrder(['customer_name' => 'Lot '.$i])->id;
        }
        $res = $this->postJson('/api/carriers/fake/ship', ['order_ids' => $ids])->assertOk();
        Http::assertNothingSent();
        $res->assertJsonPath('sent', 21)->assertJsonPath('failed', 1);
        $results = collect($res->json('results'));
        $this->assertCount(22, $results);
        $this->assertSame(['Refus transporteur (test).'], $results->where('success', false)->pluck('message')->all());
        $this->assertCount(21, $results->where('success', true));

        config(['carriers.drivers' => [
            'speedaf' => SpeedafCarrier::class,
            'ozon' => OzonCarrier::class,
            'sift' => SiftCarrier::class,
        ]]);
        app()->forgetInstance(CarrierRegistry::class);
    }

    public function test_server_refuses_more_than_fifty_orders_per_request(): void
    {
        $ids = range(1, 51);
        $this->postJson('/api/delivery-modes/speedaf/check', ['order_ids' => $ids])
            ->assertStatus(422)
            ->assertJsonPath('errors.order_ids.0', 'Maximum 50 commandes par envoi.');
        $this->postJson('/api/carriers/speedaf/ship', ['order_ids' => $ids])
            ->assertStatus(422)
            ->assertJsonPath('errors.order_ids.0', 'Maximum 50 commandes par envoi.');
    }

    public function test_local_delivery_lists_only_active_drivers_and_assigns(): void
    {
        $activeUser = User::factory()->create(['role' => User::ROLE_LIVREUR]);
        $idleUser = User::factory()->create(['role' => User::ROLE_LIVREUR]);
        $active = Driver::create(['name' => 'Yassine Actif', 'user_id' => $activeUser->id, 'phone' => '0611111111', 'tariff_livraison' => 20, 'is_active' => true]);
        Driver::create(['name' => 'Inactif', 'user_id' => $idleUser->id, 'phone' => '0622222222', 'tariff_livraison' => 20, 'is_active' => false]);

        $names = collect($this->getJson('/api/local-delivery/drivers')->assertOk()->json('drivers'))->pluck('name');
        $this->assertTrue($names->contains('Yassine Actif'));
        $this->assertFalse($names->contains('Inactif'));

        $order = $this->readyOrder();
        $this->postJson('/api/local-delivery/assign', ['order_ids' => [$order->id], 'driver_id' => $active->id])->assertOk();
        $show = $this->getJson('/api/orders/'.$order->id)->assertOk();
        $show->assertJsonPath('data.delivery_mode.type', 'local')
            ->assertJsonPath('data.delivery_mode.label', 'Livraison locale')
            ->assertJsonPath('data.delivery_mode.driver.name', 'Yassine Actif')
            ->assertJsonPath('data.delivery_mode.driver.phone', '0611111111');
        $this->assertNotEmpty($show->json('data.delivery_mode.mission.reference'));
    }

    public function test_delivery_mode_describes_each_carrier_and_the_unshipped_order(): void
    {
        $plain = $this->readyOrder();
        $this->getJson('/api/orders/'.$plain->id)->assertOk()->assertJsonPath('data.delivery_mode.type', null);

        $speedaf = $this->readyOrder();
        SpeedafShipment::create([
            'company_id' => $speedaf->company_id, 'order_id' => $speedaf->id, 'bill_code' => 'MA999',
            'state' => SpeedafShipment::STATE_CREATED, 'last_action_name' => 'Pris en charge',
        ]);
        SpeedafShipment::create([
            'company_id' => $speedaf->company_id, 'order_id' => $speedaf->id, 'bill_code' => 'MA000',
            'state' => SpeedafShipment::STATE_CANCELLED,
        ]);
        $this->getJson('/api/orders/'.$speedaf->id)->assertOk()
            ->assertJsonPath('data.delivery_mode.type', 'carrier')
            ->assertJsonPath('data.delivery_mode.key', 'speedaf')
            ->assertJsonPath('data.delivery_mode.label', 'Speedaf')
            ->assertJsonPath('data.delivery_mode.logo', '/images/carriers/speedaf.svg')
            ->assertJsonPath('data.delivery_mode.tracking', 'MA999')
            ->assertJsonPath('data.delivery_mode.status_label', 'Pris en charge')
            ->assertJsonPath('data.delivery_mode.actions.1.label', 'Étiquette')
            ->assertJsonPath('data.delivery_mode.history.0.tracking', 'MA000');

        $ozon = $this->readyOrder();
        OzonShipment::create([
            'company_id' => $ozon->company_id, 'order_id' => $ozon->id, 'tracking_number' => 'OZE123456',
            'state' => OzonShipment::STATE_CREATED, 'raw_status' => 'Pris en charge',
        ]);
        $ozonMode = $this->getJson('/api/orders/'.$ozon->id)->assertOk()->json('data.delivery_mode');
        $this->assertSame('ozon', $ozonMode['key']);
        $this->assertSame('Ozon Express', $ozonMode['label']);
        $this->assertSame('OZE123456', $ozonMode['tracking']);
        $this->assertSame('Pris en charge', $ozonMode['status_label']);
        $this->assertTrue(collect($ozonMode['actions'])->contains(fn ($a) => $a['key'] === 'track' && str_contains($a['url'], '/ozon/orders/'.$ozon->id.'/track')));
        $this->assertTrue(collect($ozonMode['actions'])->contains(fn ($a) => $a['key'] === 'delivery_note' && $a['label'] === 'BL'));

        $sift = $this->readyOrder();
        SiftShipment::create([
            'company_id' => $sift->company_id, 'order_id' => $sift->id, 'parcel_id' => 'P1',
            'tracking_number' => 'SIFT777', 'state' => SiftShipment::STATE_CREATED,
        ]);
        $siftMode = $this->getJson('/api/orders/'.$sift->id)->assertOk()->json('data.delivery_mode');
        $this->assertSame('sift', $siftMode['key']);
        $this->assertSame('Sift', $siftMode['label']);
        $this->assertSame('SIFT777', $siftMode['tracking']);
        $this->assertTrue(collect($siftMode['actions'])->contains(fn ($a) => $a['key'] === 'cancel'));
        $this->assertTrue(collect($siftMode['actions'])->pluck('key')->contains('label'));
    }

    public function test_precheck_and_speedaf_payload_use_amount_due(): void
    {
        $this->configureSpeedaf();
        $card = Order::create([
            'shopify_order_id' => 88001,
            'customer_name' => 'Sara',
            'phone' => '0612000000',
            'currency' => 'MAD',
            'confirmation_status' => 'confirmed',
            'delivery_status' => 'to_assign',
            'financial_status' => 'paid',
            'total_price' => 450,
            'total_outstanding' => 0,
            'amount_paid' => 450,
            'payment_gateway_names' => ['shopify_payments'],
            'shipping_address' => ['city' => 'Casablanca', 'address1' => '12 rue des Orangers'],
        ]);
        $partial = Order::create([
            'shopify_order_id' => 88002,
            'customer_name' => 'Nora',
            'phone' => '0612000001',
            'currency' => 'MAD',
            'confirmation_status' => 'confirmed',
            'delivery_status' => 'to_assign',
            'financial_status' => 'partially_paid',
            'total_price' => 450,
            'total_outstanding' => 350,
            'amount_paid' => 100,
            'shipping_address' => ['city' => 'Casablanca', 'address1' => '8 rue des Orangers'],
        ]);

        Http::fake();
        $rows = collect($this->postJson('/api/delivery-modes/speedaf/check', [
            'order_ids' => [$card->id, $partial->id],
        ])->assertOk()->json('rows'))->keyBy('order_id');
        Http::assertNothingSent();
        $this->assertEquals(0, $rows[$card->id]['amount_due']);
        $this->assertEquals(350, $rows[$partial->id]['amount_due']);
        $this->assertTrue($rows[$card->id]['can_send']);
        $this->assertTrue($rows[$partial->id]['can_send']);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response([
            'success' => true, 'error' => null, 'data' => ['success' => true, 'billCode' => 'MA0200000'.random_int(100, 999), 'labelUrl' => null],
        ]));
        $this->postJson('/api/carriers/speedaf/ship', ['order_ids' => [$card->id, $partial->id]])->assertOk()->assertJsonPath('sent', 2);

        $fees = [];
        foreach (Http::recorded() as [$request]) {
            /** @var HttpRequest $request */
            if (! str_contains($request->url(), 'createOrder')) {
                continue;
            }
            $fees[] = (float) json_decode($request->body(), true)['data']['codFee'];
        }
        sort($fees);
        $this->assertSame([0.0, 350.0], $fees);
    }

    public function test_permissions_hide_carriers_and_local_delivery(): void
    {
        $agent = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Setting::setValue('role_permissions', [
            'orders.ship' => [],
            'orders.assign_driver' => [],
        ]);
        $this->actingAs($agent);

        $res = $this->getJson('/api/delivery-modes')->assertOk()->assertJsonPath('can_ship', false)->assertJsonPath('can_assign_driver', false);
        $modes = collect($res->json('modes'));
        $this->assertFalse($modes->contains(fn ($m) => $m['type'] === 'carrier'));
        $this->assertFalse($modes->contains(fn ($m) => $m['type'] === 'local'));

        $order = $this->readyOrder();
        $this->postJson('/api/carriers/speedaf/ship', ['order_ids' => [$order->id]])->assertForbidden();
        $this->postJson('/api/delivery-modes/speedaf/check', ['order_ids' => [$order->id]])->assertForbidden();
    }

    public function test_an_order_with_a_local_driver_cannot_be_sent_to_a_carrier(): void
    {
        $this->configureSpeedaf();
        $livreur = User::factory()->create(['role' => User::ROLE_LIVREUR]);
        $driver = Driver::create([
            'name' => 'Yassine Actif', 'user_id' => $livreur->id, 'phone' => '0611111111',
            'tariff_livraison' => 20, 'is_active' => true,
        ]);
        $blocked = $this->readyOrder(['customer_name' => 'Déjà en local']);
        $blocked->forceFill(['driver_id' => $driver->id, 'delivery_status' => 'assigned'])->save();
        $ready = $this->readyOrder(['customer_name' => 'Prête']);
        $message = 'Commande affectée à la livraison locale (Yassine Actif) : retirez d’abord le livreur.';

        Http::fake();
        $check = collect($this->postJson('/api/delivery-modes/speedaf/check', [
            'order_ids' => [$blocked->id, $ready->id],
        ])->assertOk()->json('rows'))->keyBy('order_id');
        Http::assertNothingSent();
        $this->assertFalse($check[$blocked->id]['can_send']);
        $this->assertSame($message, $check[$blocked->id]['reason']);
        $this->assertContains($message, $check[$blocked->id]['errors']);
        $this->assertTrue($check[$ready->id]['can_send']);

        $ozon = collect($this->postJson('/api/delivery-modes/ozon/check', [
            'order_ids' => [$blocked->id],
        ])->assertOk()->json('rows'))->keyBy('order_id');
        $this->assertFalse($ozon[$blocked->id]['can_send']);
        $this->assertContains($message, $ozon[$blocked->id]['errors']);

        $preview = collect($this->postJson('/api/carriers/ozon/preview', [
            'order_ids' => [$blocked->id],
        ])->assertOk()->json('rows'))->keyBy('order_id');
        $this->assertFalse($preview[$blocked->id]['can_send']);
        $this->assertContains($message, $preview[$blocked->id]['errors']);
        Http::assertNothingSent();

        $seen = new \stdClass;
        $seen->ids = [];
        $fake = new class($seen) implements CarrierInterface
        {
            public function __construct(public \stdClass $seen) {}

            public function key(): string
            {
                return 'fake';
            }

            public function label(): string
            {
                return 'Fake Express';
            }

            public function color(): string
            {
                return '#111827';
            }

            public function unavailableReason(int $companyId): ?string
            {
                return null;
            }

            public function ship(int $companyId, iterable $orders, ?User $user = null): array
            {
                $out = [];
                foreach ($orders as $order) {
                    $this->seen->ids[] = $order->id;
                    $out[] = [
                        'order_id' => $order->id, 'reference' => $order->reference(), 'success' => true,
                        'tracking' => 'FK'.$order->id, 'message' => 'OK',
                    ];
                }

                return $out;
            }

            public function shipmentFor(Order $order): ?array
            {
                return null;
            }

            public function labels(int $companyId, iterable $orders): array
            {
                return [];
            }

            public function scopeShipped(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
            {
                return $query->whereRaw('1 = 0');
            }
        };
        app()->instance('test.fake.local-block', $fake);
        config(['carriers.drivers' => ['fake' => 'test.fake.local-block'] + config('carriers.drivers')]);
        app()->forgetInstance(CarrierRegistry::class);

        $res = $this->postJson('/api/carriers/fake/ship', [
            'order_ids' => [$blocked->id, $ready->id],
        ])->assertOk();
        $results = collect($res->json('results'))->keyBy('order_id');
        $this->assertFalse($results[$blocked->id]['success']);
        $this->assertSame($message, $results[$blocked->id]['message']);
        $this->assertTrue($results[$ready->id]['success']);
        $this->assertSame([$ready->id], $seen->ids);
        $this->assertSame(0, SpeedafShipment::query()->where('order_id', $blocked->id)->count());
        $this->assertSame(0, OzonShipment::query()->where('order_id', $blocked->id)->count());
        $this->assertSame(0, SiftShipment::query()->where('order_id', $blocked->id)->count());
        Http::assertNothingSent();

        config(['carriers.drivers' => [
            'speedaf' => SpeedafCarrier::class,
            'ozon' => OzonCarrier::class,
            'sift' => SiftCarrier::class,
        ]]);
        app()->forgetInstance(CarrierRegistry::class);
    }

    public function test_check_and_ship_ignore_another_companys_order(): void
    {
        $this->configureSpeedaf();
        $mine = $this->readyOrder(['customer_name' => 'Chez moi']);
        $other = Company::create(['name' => 'Société voisine', 'slug' => 'societe-voisine', 'is_active' => true]);
        $foreign = Order::create([
            'company_id' => $other->id,
            'shopify_order_id' => 99001,
            'customer_name' => 'Secret Client',
            'phone' => '0619999999',
            'currency' => 'MAD',
            'confirmation_status' => 'confirmed',
            'delivery_status' => 'to_assign',
            'total_price' => 999,
            'shipping_address' => ['city' => 'Rabat', 'address1' => '1 rue secrète'],
        ]);

        Http::fake();
        $rows = collect($this->postJson('/api/delivery-modes/speedaf/check', [
            'order_ids' => [$mine->id, $foreign->id],
        ])->assertOk()->json('rows'))->keyBy('order_id');
        $this->assertSame('Commande introuvable.', $rows[$foreign->id]['reason']);
        $this->assertFalse($rows[$foreign->id]['can_send']);
        $this->assertEquals(0, $rows[$foreign->id]['amount_due']);
        $this->assertTrue($rows[$mine->id]['can_send']);
        $this->assertStringNotContainsString('Secret Client', json_encode($rows));

        $preview = $this->postJson('/api/carriers/ozon/preview', [
            'order_ids' => [$mine->id, $foreign->id],
        ])->assertOk();
        $this->assertStringNotContainsString('Secret Client', $preview->getContent());
        $this->assertSame([$mine->id], collect($preview->json('rows'))->pluck('order_id')->all());
        Http::assertNothingSent();

        $this->postJson('/api/carriers/speedaf/ship', ['order_ids' => [$foreign->id]])
            ->assertNotFound()
            ->assertJsonPath('message', 'Aucune commande trouvée.');
        Http::assertNothingSent();

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response([
            'success' => true, 'error' => null, 'data' => ['success' => true, 'billCode' => 'MA0200000123', 'labelUrl' => null],
        ]));
        $shipped = $this->postJson('/api/carriers/speedaf/ship', [
            'order_ids' => [$mine->id, $foreign->id],
        ])->assertOk();
        $this->assertSame([$mine->id], collect($shipped->json('results'))->pluck('order_id')->all());
        $this->assertSame(0, SpeedafShipment::query()->where('order_id', $foreign->id)->count());
        $this->assertSame(1, SpeedafShipment::query()->where('order_id', $mine->id)->count());
        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('Secret Client', $request->body());
        }
    }
}
