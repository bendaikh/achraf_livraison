<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\OzonApiLog;
use App\Models\OzonCity;
use App\Models\OzonDeliveryNote;
use App\Models\OzonSetting;
use App\Models\OzonShipment;
use App\Models\SavRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** T14 — Ozon Express carrier (all API calls faked: no credentials yet). */
class OzonIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected const KEY = 'sk_live_SECRET_9f8e7d6c';

    protected const ID = '4242';

    protected const API = 'https://api.ozonexpress.ma/customers/4242/sk_live_SECRET_9f8e7d6c/';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signInAdmin();
        Http::preventStrayRequests();
    }

    protected function cities(): void
    {
        Http::fake(['https://api.ozonexpress.ma/cities' => Http::response(['CITIES' => [
            '37' => ['ID' => 37, 'REF' => 'AGA', 'NAME' => 'Agadir', 'DELIVERED-PRICE' => 35, 'RETURNED-PRICE' => 0, 'REFUSED-PRICE' => 10],
            '41' => ['ID' => 41, 'REF' => 'CMA', 'NAME' => 'Casablanca – Maarif', 'DELIVERED-PRICE' => 20, 'RETURNED-PRICE' => 0, 'REFUSED-PRICE' => 5],
            '58' => ['ID' => 58, 'REF' => 'RBA', 'NAME' => 'Rabat', 'DELIVERED-PRICE' => 30, 'RETURNED-PRICE' => 0, 'REFUSED-PRICE' => 10],
            '99' => ['ID' => 99, 'REF' => 'TNG', 'NAME' => 'Tanger', 'DELIVERED-PRICE' => 35, 'RETURNED-PRICE' => 0, 'REFUSED-PRICE' => 10],
        ], 'DEBUG' => null])]);
        $this->postJson('/api/integrations/ozon/cities/sync')->assertOk();
    }

    protected function configure(array $extra = []): OzonSetting
    {
        $s = OzonSetting::forCompany($this->admin->resolveCompanyId());
        $s->fill(['enabled' => true, 'customer_id' => self::ID] + $extra);
        $s->api_key = self::KEY;
        $s->save();
        app()->forgetInstance(CarrierRegistry::class);

        return $s;
    }

    protected function confirmedOrder(array $data = []): Order
    {
        $order = $this->order($data + [
            'customer_name' => 'Fatima Zahra', 'customer_phone' => '+212 6 12 34 56 78', 'city' => 'Rabat', 'address' => '12 Rue Atlas, Agdal',
            'amount' => 250, 'product_name' => 'Robe', 'quantity' => 2, 'payment_method' => 'cod', 'note' => 'Appeler avant',
        ]);
        $order->forceFill([
            'confirmation_status' => 'confirmed', 'delivery_status' => 'to_assign',
            'line_items' => [
                ['title' => 'Robe', 'quantity' => 2, 'sku' => 'ROBE-M', 'price' => 100],
                ['title' => 'Ceinture', 'quantity' => 1, 'sku' => '', 'price' => 50],
            ],
        ])->save();

        return $order->fresh();
    }

    protected function addParcelResponse(string $tn = 'OZ123456789', int $city = 58): array
    {
        return ['CHECK_API' => ['RESULT' => 'SUCCESS', 'MESSAGE' => ''], 'ADD-PARCEL' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Colis ajouté', 'NEW-PARCEL' => [
            'TRACKING-NUMBER' => $tn, 'RECEIVER' => 'Fatima Zahra', 'PHONE' => '0612345678', 'CITY_ID' => (string) $city, 'CITY_NAME' => 'Rabat',
            'ADDRESS' => '12 Rue Atlas, Agdal', 'PRICE' => '250', 'DELIVERED-PRICE' => '30', 'RETURNED-PRICE' => '0', 'REFUSED-PRICE' => '10',
        ]]];
    }

    protected function trackingResponse(string $tn, string $statut, ?string $comment = null): array
    {
        return ['CHECK_API' => ['RESULT' => 'SUCCESS'], 'TRACKING' => ['RESULT' => 'SUCCESS', 'TRACKING-NUMBER' => $tn,
            'LAST_TRACKING' => ['STATUT' => $statut, 'COMMENT' => $comment, 'TIME' => '1759600000', 'TIME_STR' => '2026-10-04 18:30'],
            'HISTORY' => ['1' => ['STATUT' => 'Nouveau Colis', 'COMMENT' => null, 'TIME_STR' => '2026-10-04 10:00'],
                '2' => ['STATUT' => $statut, 'COMMENT' => $comment, 'TIME_STR' => '2026-10-04 18:30']]]];
    }

    protected function assertNoKeyAnywhere(): void
    {
        foreach (['ozon_api_logs', 'ozon_shipments', 'ozon_delivery_notes', 'order_status_histories'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString(self::KEY, $dump, "API key leaked in {$table}");
        }
        $log = storage_path('logs/laravel.log');
        if (is_file($log)) {
            $this->assertStringNotContainsString(self::KEY, (string) file_get_contents($log));
        }
    }

    /* ------------------------------------------------------------------ settings */

    public function test_settings_never_expose_the_api_key_and_store_it_encrypted(): void
    {
        $this->putJson('/api/integrations/ozon', ['enabled' => true, 'customer_id' => self::ID])->assertStatus(422);

        $res = $this->putJson('/api/integrations/ozon', ['enabled' => true, 'customer_id' => self::ID, 'api_key' => self::KEY, 'default_stock' => 1, 'default_open' => false])
            ->assertOk()->assertJsonPath('data.has_api_key', true)->assertJsonPath('data.customer_id', self::ID)
            ->assertJsonPath('data.default_stock', 1)->assertJsonPath('data.default_open', false)->assertJsonPath('data.bulk_enabled', false);
        $this->assertStringNotContainsString(self::KEY, $res->getContent());
        $this->assertSame('••••••••7d6c', $res->json('data.api_key_hint'));
        $this->assertStringNotContainsString(self::KEY, $this->getJson('/api/integrations/ozon')->getContent());
        $raw = DB::table('ozon_settings')->value('api_key');
        $this->assertNotSame(self::KEY, $raw);
        $this->assertSame(self::KEY, OzonSetting::first()->api_key);

        // Saving without a key keeps the existing one.
        $this->putJson('/api/integrations/ozon', ['customer_id' => self::ID, 'api_key' => ''])->assertOk()->assertJsonPath('data.has_api_key', true);
        // Bulk flag is a setting (ozon_bulk_enabled), off by default.
        $this->assertFalse((bool) Setting::getValue('ozon_bulk_enabled'));
        $this->putJson('/api/integrations/ozon', ['bulk_enabled' => true])->assertOk()->assertJsonPath('data.bulk_enabled', true);
        $this->assertTrue((bool) Setting::getValue('ozon_bulk_enabled'));
    }

    public function test_connection_test_reports_wrong_key_without_leaking_it(): void
    {
        $this->configure();
        Http::fake([self::API.'tracking' => Http::sequence()
            ->push(['CHECK_API' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Please verify your API Key']])
            ->push(['CHECK_API' => ['RESULT' => 'SUCCESS', 'MESSAGE' => ''], 'TRACKING' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Colis introuvable']])]);

        $bad = $this->postJson('/api/integrations/ozon/test')->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('data.state', 'erreur');
        $this->assertStringContainsString('Please verify your API Key', $bad->json('message'));
        $this->assertDatabaseHas('ozon_api_logs', ['action' => 'test', 'endpoint' => 'tracking']);

        $this->postJson('/api/integrations/ozon/test')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('data.state', 'connecte');
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), self::API.'tracking'));
        $this->assertNoKeyAnywhere();
    }

    public function test_connection_errors_are_redacted(): void
    {
        $this->configure();
        Http::fake([self::API.'*' => fn () => throw new ConnectionException('cURL error 28: timeout for '.self::API.'tracking')]);

        $res = $this->postJson('/api/integrations/ozon/test')->assertStatus(422);
        $this->assertStringNotContainsString(self::KEY, $res->getContent());
        $this->assertStringContainsString('/customers/4242/••••', $res->json('message'));
        $this->assertNoKeyAnywhere();
    }

    /* ------------------------------------------------------------------ cities */

    public function test_cities_sync_auto_match_and_manual_mapping(): void
    {
        $this->confirmedOrder(['city' => 'tanger ']);
        $this->confirmedOrder(['city' => 'Casablanca']);
        $this->cities();
        $this->assertSame(4, OzonCity::count());

        $rows = collect($this->getJson('/api/integrations/ozon/city-mappings')->assertOk()->json('data'))->keyBy('city');
        $this->assertSame(99, $rows['tanger']['ozon_city']['id']);
        $this->assertNull($rows['Casablanca']['ozon_city']);
        $this->assertSame(41, $rows['Casablanca']['suggestions'][0]['id']);

        $this->putJson('/api/integrations/ozon/city-mappings', ['city' => 'Casablanca', 'ozon_city_id' => 41])->assertOk()->assertJsonPath('data.source', 'manual');
        $rows = collect($this->getJson('/api/integrations/ozon/city-mappings')->json('data'))->keyBy('city');
        $this->assertSame(41, $rows['Casablanca']['ozon_city']['id']);
        $this->putJson('/api/integrations/ozon/city-mappings', ['city' => 'Casablanca', 'ozon_city_id' => 12345])->assertStatus(422);
        $this->getJson('/api/ozon/cities?q=casa')->assertOk()->assertJsonPath('data.0.id', 41);
    }

    /* ------------------------------------------------------------------ preview + create */

    public function test_preview_lists_blocking_errors_and_values(): void
    {
        $this->configure();
        $this->cities();
        $order = $this->confirmedOrder(['city' => 'Casablanca']);

        $row = $this->postJson('/api/carriers/ozon/preview', ['order_ids' => [$order->id]])->assertOk()->json('rows.0');
        $this->assertFalse($row['can_send']);
        $this->assertStringContainsString('Mapping villes', $row['errors'][0]);
        $this->assertSame(250.0, (float) $row['price']);
        $this->assertSame('0612345678', $row['phone']);
        $this->assertTrue($row['open']);
        $this->assertFalse($row['fragile']);
        $this->assertFalse($row['replace']);
        $this->assertSame([['ref' => 'ROBE-M', 'qnty' => 2]], $row['products']);

        $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$order->id]])->assertStatus(422);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/customers/'));
    }

    public function test_send_single_order_saves_shipment_timeline_and_generic_columns(): void
    {
        $this->configure(['default_stock' => 0, 'stock_by_type' => ['livraison' => 1, 'echange' => null], 'default_fragile' => false]);
        $this->cities();
        $order = $this->confirmedOrder();
        Http::fake([self::API.'add-parcel' => Http::response($this->addParcelResponse())]);

        $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$order->id], 'options' => ['open' => false, 'fragile' => true]])->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('results.0.tracking', 'OZ123456789')
            ->assertJsonPath('data.shipment.carrier', 'ozon')
            ->assertJsonPath('data.shipment.carrier_label', 'Ozon Express')
            ->assertJsonPath('data.shipment.tracking', 'OZ123456789')
            ->assertJsonPath('data.carrier', 'Ozon Express');

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/add-parcel')) {
                return false;
            }
            $f = collect($r->data())->mapWithKeys(fn ($p) => [$p['name'] => $p['contents']])->all();

            return $r->isMultipart()
                && $f['parcel-city'] === '58' && $f['parcel-receiver'] === 'Fatima Zahra' && $f['parcel-phone'] === '0612345678'
                && $f['parcel-price'] === '250' && $f['parcel-stock'] === '1' && $f['parcel-open'] === '2' && $f['parcel-fragile'] === '1'
                && $f['parcel-replace'] === '0' && $f['parcel-note'] === 'Appeler avant'
                && json_decode($f['products'], true) === [['ref' => 'ROBE-M', 'qnty' => 2]]
                && ! isset($f['tracking-number']) && ! str_contains(json_encode($f), self::KEY);
        });

        $s = OzonShipment::firstOrFail();
        $this->assertSame(['OZ123456789', 58, 'Rabat', 250.0, 30.0, 0.0, 10.0], [$s->tracking_number, $s->city_id, $s->city_name, (float) $s->price, (float) $s->delivered_price, (float) $s->returned_price, (float) $s->refused_price]);
        $this->assertSame(['ozon_sent', 'ozon_tracking'], OrderStatusHistory::where('order_id', $order->id)->where('kind', 'ozon')->orderBy('id')->pluck('status_code')->all());
        $this->assertSame($this->admin->id, OrderStatusHistory::where('kind', 'ozon')->value('user_id'));

        // Commandes list: Livreur/Transporteur + Suivi columns use the generic shipment.
        $list = collect($this->getJson('/api/orders?per_page=50')->assertOk()->json('data'))->firstWhere('id', $order->id);
        $this->assertSame('Ozon Express', $list['shipment']['carrier_label']);
        $this->assertSame('OZ123456789', $list['shipment']['tracking']);
        $this->getJson('/api/orders?carrier=ozon')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNoKeyAnywhere();
    }

    public function test_anti_duplicate_never_creates_a_second_parcel(): void
    {
        $this->configure();
        $this->cities();
        $order = $this->confirmedOrder();
        Http::fake([self::API.'add-parcel' => Http::response($this->addParcelResponse())]);
        $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$order->id]])->assertOk();

        $res = $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$order->id]])->assertStatus(422);
        $this->assertStringContainsString('Cette commande est déjà envoyée à Ozon', $res->json('message'));
        $this->assertTrue($res->json('results.0.duplicate'));
        $this->postJson('/api/carriers/ozon/preview', ['order_ids' => [$order->id]])->assertJsonPath('rows.0.already.tracking_number', 'OZ123456789')->assertJsonPath('rows.0.can_send', false);
        Http::assertSentCount(1);
        $this->assertSame(1, OzonShipment::count());
        // Local delivery is blocked too, other carriers refuse it.
        $this->assertSame('Déjà envoyée à Ozon Express.', $order->fresh()->localAssignmentBlocker());
    }

    public function test_concurrent_send_of_the_same_order_is_refused(): void
    {
        $this->configure();
        $this->cities();
        $order = $this->confirmedOrder();
        Http::fake([self::API.'add-parcel' => Http::response($this->addParcelResponse())]);
        $lock = Cache::lock('ozon-parcel-'.$order->id.'-0', 60);
        $this->assertTrue($lock->get());

        $res = $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$order->id]])->assertStatus(422);
        $this->assertStringContainsString('déjà en cours', $res->json('results.0.message'));
        Http::assertNothingSent();

        $lock->release();
        $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$order->id]])->assertOk();
        $this->assertSame(1, OzonShipment::count());
    }

    public function test_bulk_actions_are_disabled_until_enabled(): void
    {
        $this->configure();
        $this->cities();
        $a = $this->confirmedOrder();
        $b = $this->confirmedOrder(['customer_name' => 'Karim']);
        $carrier = collect($this->getJson('/api/carriers')->json('carriers'))->firstWhere('key', 'ozon');
        $this->assertFalse($carrier['bulk_enabled']);
        $this->assertTrue($carrier['preview']);
        $this->assertTrue($carrier['delivery_notes']);

        $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$a->id, $b->id]])->assertStatus(422)->assertJsonFragment(['message' => 'Actions groupées Ozon désactivées tant que le cycle complet n’est pas validé (Intégrations → Ozon Express).']);
        $this->postJson('/api/ozon/delivery-notes', ['order_ids' => [$a->id, $b->id]])->assertStatus(422);
        $this->postJson('/api/ozon/labels', ['order_ids' => [$a->id, $b->id]])->assertStatus(422);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/customers/'));

        Setting::setValue('ozon_bulk_enabled', true);
        Http::fake([self::API.'add-parcel' => Http::sequence()->push($this->addParcelResponse('OZ1'))->push($this->addParcelResponse('OZ2'))]);
        $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$a->id, $b->id]])->assertOk()->assertJsonPath('sent', 2);
    }

    public function test_api_error_is_logged_without_key_and_can_be_retried(): void
    {
        $this->configure();
        $this->cities();
        $order = $this->confirmedOrder();
        Http::fake([self::API.'add-parcel' => Http::sequence()
            ->push(['CHECK_API' => ['RESULT' => 'SUCCESS'], 'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Ville invalide']])
            ->push($this->addParcelResponse('OZ777'))]);

        $res = $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$order->id], 'options' => ['fragile' => true]])->assertStatus(422);
        $this->assertSame('Ozon : Ville invalide', $res->json('message'));
        $log = OzonApiLog::firstOrFail();
        $this->assertSame(['create_parcel', 'add-parcel', $order->id], [$log->action, $log->endpoint, $log->order_id]);
        $this->assertSame('58', $log->payload['parcel-city']);
        $this->assertSame($this->admin->id, $log->user_id);

        $row = $this->getJson('/api/integrations/ozon/logs')->assertOk()->json('data.0');
        $this->assertTrue($row['retryable']);
        $this->assertSame('Création du colis', $row['action_label']);

        $this->postJson("/api/integrations/ozon/logs/{$log->id}/retry")->assertOk()->assertJsonPath('data.resolved', true);
        $this->assertSame('OZ777', OzonShipment::firstOrFail()->tracking_number);
        $this->assertSame(1, (int) OzonShipment::first()->parcel_fragile, 'retry keeps the options chosen');
        $this->assertNoKeyAnywhere();
    }

    /* ------------------------------------------------------------------ tracking */

    protected function shipped(): array
    {
        $this->configure();
        $this->cities();
        $order = $this->confirmedOrder();
        Http::fake([self::API.'add-parcel' => Http::response($this->addParcelResponse())]);
        $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$order->id]])->assertOk();

        return [$order, OzonShipment::firstOrFail()];
    }

    public function test_refresh_and_single_tracking_apply_the_mapped_status(): void
    {
        [$order] = $this->shipped();
        Http::fake([
            self::API.'parcel-info' => Http::response(['CHECK_API' => ['RESULT' => 'SUCCESS'], 'PARCEL-INFO' => ['RESULT' => 'SUCCESS', 'INFOS' => [
                'TRACKING-NUMBER' => 'OZ123456789', 'RECEIVER' => 'Fatima Z.', 'CITY_ID' => '58', 'CITY_NAME' => 'Rabat', 'PRICE' => '250', 'DELIVERED-PRICE' => '32',
            ]]]),
            self::API.'tracking' => Http::sequence()
                ->push($this->trackingResponse('OZ123456789', 'En cours de livraison'))
                ->push($this->trackingResponse('OZ123456789', 'Livré', 'Remis au client')),
        ]);

        $this->postJson("/api/ozon/orders/{$order->id}/refresh")->assertOk()
            ->assertJsonPath('data.ozon.receiver', 'Fatima Z.')
            ->assertJsonPath('data.ozon.delivered_price', 32)
            ->assertJsonPath('data.ozon.raw_status', 'En cours de livraison')
            ->assertJsonPath('data.delivery_status.code', 'in_progress');

        $this->postJson("/api/ozon/orders/{$order->id}/track")->assertOk()
            ->assertJsonPath('data.ozon.raw_status', 'Livré')
            ->assertJsonPath('data.ozon.state', 'delivered')
            ->assertJsonPath('data.delivery_status.code', 'delivered')
            ->assertJsonPath('data.collected_amount', 250);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->where('status_code', 'ozon_status')->count());
        $this->assertSame('Livré', OzonShipment::first()->raw_status);
    }

    public function test_unknown_status_is_kept_raw_and_added_to_the_mapping_table(): void
    {
        [$order] = $this->shipped();
        Http::fake([self::API.'tracking' => Http::response($this->trackingResponse('OZ123456789', 'Colis en litige'))]);

        $this->postJson("/api/ozon/orders/{$order->id}/track")->assertOk()->assertJsonPath('data.ozon.raw_status', 'Colis en litige')
            ->assertJsonPath('data.delivery_status.code', 'to_assign');
        $mapping = collect($this->getJson('/api/integrations/ozon')->json('data.status_mapping'))->keyBy('raw');
        $this->assertArrayHasKey('Colis en litige', $mapping->all());
        $this->assertNull($mapping['Colis en litige']['code']);

        // Configure it, then a later identical status is not re-applied but new ones are.
        $rows = $mapping->values()->map(fn ($r) => ['raw' => $r['raw'], 'code' => $r['raw'] === 'Colis en litige' ? 'failed' : $r['code']])->all();
        $this->putJson('/api/integrations/ozon', ['status_mapping' => $rows])->assertOk();
        $this->assertSame('failed', OzonSetting::first()->status_mapping['Colis en litige']);
    }

    public function test_scheduled_bulk_sync_uses_one_json_request(): void
    {
        $this->configure();
        $this->cities();
        Setting::setValue('ozon_bulk_enabled', true);
        $a = $this->confirmedOrder();
        $b = $this->confirmedOrder(['customer_name' => 'Karim']);
        Http::fake([self::API.'add-parcel' => Http::sequence()->push($this->addParcelResponse('OZA'))->push($this->addParcelResponse('OZB'))]);
        $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$a->id, $b->id]])->assertOk();

        Http::fake([self::API.'tracking' => Http::response(['CHECK_API' => ['RESULT' => 'SUCCESS'], 'TRACKING' => [
            'OZA' => $this->trackingResponse('OZA', 'Livré')['TRACKING'],
            'OZB' => $this->trackingResponse('OZB', 'Refusé', 'Client absent')['TRACKING'],
        ]])]);
        Artisan::call('ozon:sync');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/tracking') && $r->isJson() && $r->data() === ['tracking-number' => ['OZA', 'OZB']]);
        $this->assertSame('delivered', $a->fresh()->delivery_status);
        $this->assertSame('failed', $b->fresh()->delivery_status);
        $this->assertSame('Client absent', $b->fresh()->delivery_failure_reason);
        $this->assertNotNull(OzonSetting::first()->last_synced_at);
        $this->assertNull(OrderStatusHistory::where('order_id', $a->id)->where('status_code', 'ozon_status')->value('user_id'));
        $this->assertSame('Système', OrderStatusHistory::where('order_id', $a->id)->where('status_code', 'ozon_status')->first()->data['actor']);
    }

    /* ------------------------------------------------------------------ delivery notes */

    public function test_delivery_note_requires_tracking_then_creates_adds_and_saves(): void
    {
        [$order] = $this->shipped();
        $other = $this->confirmedOrder(['customer_name' => 'Sans colis']);
        Setting::setValue('ozon_bulk_enabled', true);

        $this->postJson('/api/ozon/delivery-notes', ['order_ids' => [$order->id, $other->id]])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Sans suivi Ozon : '.$other->reference().'. Envoyez-les d’abord à Ozon.']);

        Http::fake([
            self::API.'add-delivery-note' => Http::response(['CHECK_API' => ['RESULT' => 'SUCCESS'], 'ADD-BL' => ['RESULT' => 'SUCCESS', 'NEW-BL' => ['REF' => 'BL-2026-77', 'ID' => 77]]]),
            self::API.'add-parcel-to-delivery-note' => Http::response(['CHECK_API' => ['RESULT' => 'SUCCESS'], 'ADD-PARCEL-BL' => ['RESULT' => 'SUCCESS']]),
            self::API.'save-delivery-note' => Http::response(['CHECK_API' => ['RESULT' => 'SUCCESS'], 'SAVE-BL' => ['RESULT' => 'SUCCESS']]),
        ]);
        $res = $this->postJson('/api/ozon/delivery-notes', ['order_ids' => [$order->id]])->assertOk()
            ->assertJsonPath('delivery_note.ref', 'BL-2026-77')
            ->assertJsonPath('delivery_note.state', 'saved')
            ->assertJsonPath('delivery_note.items.0.tracking_number', 'OZ123456789');
        $this->assertSame('https://client.ozoneexpress.ma/pdf-delivery-note?dn-ref=BL-2026-77', $res->json('delivery_note.documents.bl_pdf'));
        $this->assertSame('https://client.ozoneexpress.ma/pdf-delivery-note-tickets-4-4?dn-ref=BL-2026-77', $res->json('delivery_note.documents.labels_10x10'));
        Http::assertSent(function (HttpRequest $r) {
            $f = collect($r->data())->mapWithKeys(fn ($p) => [$p['name'] => $p['contents']])->all();

            return str_ends_with($r->url(), '/add-parcel-to-delivery-note') && $f === ['Ref' => 'BL-2026-77', 'Codes[0]' => 'OZ123456789'];
        });
        $this->assertSame(['ozon_sent', 'ozon_tracking', 'ozon_bl_added', 'ozon_bl_saved'], OrderStatusHistory::where('order_id', $order->id)->where('kind', 'ozon')->orderBy('id')->pluck('status_code')->all());

        $this->postJson('/api/ozon/labels', ['order_ids' => [$order->id]])->assertOk()
            ->assertJsonPath('delivery_notes.0.ref', 'BL-2026-77')
            ->assertJsonPath('delivery_notes.0.documents.labels_a4', 'https://client.ozoneexpress.ma/pdf-delivery-note-tickets?dn-ref=BL-2026-77');
        $this->getJson('/api/integrations/ozon/delivery-notes')->assertOk()->assertJsonPath('data.0.items.0.order_id', $order->id);
        $this->getJson("/api/orders/{$order->id}")->assertOk()->assertJsonPath('data.shipment.delivery_note_ref', 'BL-2026-77');
        // Already in a saved BL: refused.
        $this->postJson('/api/ozon/delivery-notes', ['order_ids' => [$order->id]])->assertStatus(422);
    }

    public function test_failed_delivery_note_step_can_be_resumed_from_the_log(): void
    {
        [$order] = $this->shipped();
        Http::fake([
            self::API.'add-delivery-note' => Http::response(['CHECK_API' => ['RESULT' => 'SUCCESS'], 'ADD-BL' => ['RESULT' => 'SUCCESS', 'NEW-BL' => ['REF' => 'BL-9']]]),
            self::API.'add-parcel-to-delivery-note' => Http::response(['CHECK_API' => ['RESULT' => 'SUCCESS'], 'ADD-PARCEL-BL' => ['RESULT' => 'SUCCESS']]),
            self::API.'save-delivery-note' => Http::sequence()
                ->push(['CHECK_API' => ['RESULT' => 'SUCCESS'], 'SAVE-BL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Erreur temporaire']])
                ->push(['CHECK_API' => ['RESULT' => 'SUCCESS'], 'SAVE-BL' => ['RESULT' => 'SUCCESS']]),
        ]);
        $this->postJson('/api/ozon/delivery-notes', ['order_ids' => [$order->id]])->assertStatus(422);
        $note = OzonDeliveryNote::firstOrFail();
        $this->assertSame(['BL-9', 'filled'], [$note->ref, $note->state]);
        $log = OzonApiLog::where('action', 'delivery_note')->firstOrFail();

        $this->postJson("/api/integrations/ozon/logs/{$log->id}/retry")->assertOk();
        $this->assertSame('saved', $note->fresh()->state);
        $count = fn (string $ep) => Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/'.$ep))->count();
        $this->assertSame([1, 1, 2], [$count('add-delivery-note'), $count('add-parcel-to-delivery-note'), $count('save-delivery-note')]);
    }

    /* ------------------------------------------------------------------ SAV exchange */

    public function test_sav_exchange_sets_parcel_replace_and_type_stock(): void
    {
        $this->configure(['default_stock' => 1, 'stock_by_type' => ['livraison' => null, 'echange' => 0]]);
        $this->cities();
        $order = $this->confirmedOrder();
        $order->forceFill(['delivery_status' => 'delivered'])->save();
        $sav = SavRequest::create([
            'company_id' => $this->admin->resolveCompanyId(), 'reference' => 'SAV-1', 'order_id' => $order->id, 'type' => 'echange', 'status' => 'to_assign',
            'reason' => 'Mauvaise référence', 'customer_name' => 'Fatima Zahra', 'phone' => '0612345678', 'address' => '12 Rue Atlas', 'city' => 'Rabat',
        ]);
        $sav->items()->create(['direction' => 'deliver', 'title' => 'Robe L', 'sku' => 'ROBE-L', 'quantity' => 1, 'unit_price' => 100]);
        $sav->items()->create(['direction' => 'pickup', 'title' => 'Robe M', 'sku' => 'ROBE-M', 'quantity' => 1, 'unit_price' => 100]);
        Http::fake([self::API.'add-parcel' => Http::response($this->addParcelResponse('OZX'))]);

        $this->getJson("/api/sav/{$sav->id}/ozon")->assertOk()->assertJsonPath('rows.0.replace', true)->assertJsonPath('rows.0.stock', 0)->assertJsonPath('rows.0.can_send', true);
        $this->postJson("/api/sav/{$sav->id}/ozon", ['price' => 0])->assertOk()->assertJsonPath('shipment.kind', 'echange');

        Http::assertSent(function (HttpRequest $r) {
            $f = collect($r->data())->mapWithKeys(fn ($p) => [$p['name'] => $p['contents']])->all();

            return $f['parcel-replace'] === '1' && $f['parcel-stock'] === '0' && $f['parcel-price'] === '0'
                && json_decode($f['products'], true) === [['ref' => 'ROBE-L', 'qnty' => 1]] && str_starts_with($f['parcel-nature'], 'Échange');
        });
        // The order itself still counts as not shipped with Ozon (exchange parcel is separate).
        $this->assertNull($order->fresh()->currentOzonShipment());
        $this->postJson("/api/sav/{$sav->id}/ozon")->assertStatus(422)->assertJsonPath('duplicate', true);
    }

    public function test_other_carriers_stay_available(): void
    {
        $keys = collect($this->getJson('/api/carriers')->assertOk()->json('carriers'))->pluck('key')->all();
        $this->assertSame(['speedaf', 'ozon'], $keys);
        $ozon = collect($this->getJson('/api/carriers')->json('carriers'))->firstWhere('key', 'ozon');
        $this->assertFalse($ozon['available']);
        $this->assertStringContainsString('Ozon Express', $ozon['reason']);
    }
}
