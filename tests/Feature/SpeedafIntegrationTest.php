<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SpeedafSetting;
use App\Models\SpeedafShipment;
use App\Models\User;
use App\Services\Speedaf\SpeedafClient;
use App\Services\Speedaf\SpeedafStatusMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\SpeedafSupportTest;

/** Speedaf integration, API mocked with the responses documented in the Speedaf Open API PDF. */
class SpeedafIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected User $admin;

    protected const UAT = 'https://uat-api.speedaf.com/open-api/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signInAdmin();
    }

    protected function configure(array $attrs = []): SpeedafSetting
    {
        $s = SpeedafSetting::forCompany($this->admin->resolveCompanyId());
        $s->fill($attrs + [
            'enabled' => true, 'environment' => 'uat', 'app_code' => 'MA000025', 'customer_code' => 'MA000025',
            'platform_source' => 'TEST Platform', 'sender_name' => 'Lavfast', 'sender_mobile' => '0522 00 00 00',
            'sender_address' => 'Bd Zerktouni', 'sender_city' => 'Casablanca',
        ])->save();

        return $s->fresh();
    }

    protected function confirmedOrder(array $data = []): Order
    {
        $order = $this->order($data + [
            'customer_name' => 'Fatima Zahra', 'customer_phone' => '+212 6 12 34 56 78', 'city' => 'Mohammédia',
            'address' => '12 Rue Atlas', 'amount' => 250, 'product_name' => 'Robe été', 'quantity' => 2, 'payment_method' => 'cod',
        ]);
        $order->forceFill(['confirmation_status' => 'confirmed', 'delivery_status' => 'to_assign'])->save();

        return $order->fresh();
    }

    protected function treeResponse(): array
    {
        return ['success' => true, 'error' => null, 'data' => ['success' => true, 'error' => null, 'data' => [
            'code' => 'MA', 'name' => 'Morocco', 'children' => [
                ['code' => 'MAR00024', 'name' => 'Casablanca - Settat', 'type' => 1, 'children' => [
                    ['code' => 'C1', 'name' => 'Casablanca', 'type' => 2, 'children' => [['code' => 'D1', 'name' => 'Maarif']]],
                    ['code' => 'C2', 'name' => 'MOHAMMEDIA', 'type' => 2, 'children' => [['code' => 'D2', 'name' => 'Mohammedia']]],
                ]],
            ],
        ]]];
    }

    /** createOrder response sample of the PDF (§3.1). */
    protected function createResponse(string $billCode = 'MA020000031836'): array
    {
        return ['success' => true, 'error' => null, 'data' => [
            'labelBase64' => null, 'expressAging' => '', 'success' => true, 'billCode' => $billCode, 'labelUrl' => null, 'message' => null, 'customerOrderNo' => '',
        ]];
    }

    protected function fakeApi(array $extra = []): void
    {
        Http::fake($extra + [
            self::UAT.'common/area/v2/getTreeByCountryCode*' => Http::response($this->treeResponse()),
            self::UAT.'express/order/v2/createOrder*' => Http::response($this->createResponse()),
        ]);
    }

    /* ------------------------------------------------------------------ settings */

    public function test_settings_are_saved_with_encrypted_masked_secret(): void
    {
        $this->getJson('/api/integrations/speedaf')->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.environment', 'uat')
            ->assertJsonPath('data.has_secret_key', false)
            ->assertJsonPath('data.status_mapping.5', 'delivered');

        $res = $this->putJson('/api/integrations/speedaf', [
            'enabled' => true, 'environment' => 'production', 'app_code' => ' NG000099 ', 'customer_code' => 'NG000099',
            'secret_key' => 'super-secret-1234', 'platform_source' => 'Lavfast', 'sender_name' => 'Lavfast', 'sender_mobile' => '0600000000',
            'sender_address' => 'Bd Anfa', 'sender_city' => 'Casablanca', 'status_mapping' => ['5' => 'delivered', '4' => null, 'IP05' => 'unknown_code'],
        ])->assertOk();

        $res->assertJsonPath('data.app_code', 'NG000099')
            ->assertJsonPath('data.base_url', 'https://apis.speedaf.com/')
            ->assertJsonPath('data.has_secret_key', true)
            ->assertJsonPath('data.secret_key_hint', '••••••••1234')
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.status_mapping.4', null)
            ->assertJsonPath('data.status_mapping.IP05', null);
        $this->assertStringNotContainsString('super-secret-1234', $res->getContent());

        $raw = DB::table('speedaf_settings')->value('secret_key');
        $this->assertNotSame('super-secret-1234', $raw);
        $this->assertSame('super-secret-1234', SpeedafSetting::first()->secret_key);

        // Empty secret keeps the stored one.
        $this->putJson('/api/integrations/speedaf', ['secret_key' => '', 'sender_name' => 'Lavfast 2'])->assertOk();
        $this->assertSame('super-secret-1234', SpeedafSetting::first()->secret_key);
    }

    public function test_cannot_enable_without_credentials(): void
    {
        $this->putJson('/api/integrations/speedaf', ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Renseignez l’App Code et le Code client avant d’activer Speedaf.');
    }

    public function test_connection_test_success_and_failure(): void
    {
        $this->configure();
        Http::fake([
            self::UAT.'common/area/v2/new/getArea*' => Http::sequence()
                ->push(['success' => true, 'error' => null, 'data' => ['success' => true, 'error' => null, 'data' => [
                    ['code' => 'MAR00024', 'name' => 'Casablanca - Settat'], ['code' => 'MAR00016', 'name' => 'Rabat - Salé - Kénitra'],
                ]]])
                ->push(['success' => false, 'error' => ['code' => '70401', 'message' => 'Invalid AppCode, please check or contact support staff'], 'data' => null]),
        ]);

        $this->postJson('/api/integrations/speedaf/test')->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['ok' => true]);
        $this->assertStringContainsString('2 région(s)', SpeedafSetting::first()->last_test_message);

        $this->postJson('/api/integrations/speedaf/test', ['app_code' => 'BAD'])->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Speedaf : App Code invalide : vérifiez-le ou contactez Speedaf. (code 70401)');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'appCode=BAD') && preg_match('/timestamp=\d{13}/', $r->url()));
        // A test of unsaved values does not overwrite the saved result.
        $this->assertTrue(SpeedafSetting::first()->last_test_ok);
    }

    /* ------------------------------------------------------------------ create */

    public function test_send_order_maps_fields_and_stores_waybill(): void
    {
        $this->configure();
        $this->fakeApi();
        $order = $this->confirmedOrder();

        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]])->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('results.0.bill_code', 'MA020000031836')
            ->assertJsonPath('data.speedaf.bill_code', 'MA020000031836')
            ->assertJsonPath('data.carrier', 'Speedaf');

        Http::assertSent(function (HttpRequest $r) {
            if (! str_contains($r->url(), 'express/order/v2/createOrder')) {
                return false;
            }
            $this->assertStringStartsWith(self::UAT.'express/order/v2/createOrder?appCode=MA000025&timestamp=', $r->url());
            $this->assertSame('POST', $r->method());
            $d = json_decode($r->body(), true)['data'];
            $this->assertSame('MA000025', $d['customerCode']);
            $this->assertSame('TEST Platform', $d['platformSource']);
            $this->assertSame('Fatima Zahra', $d['acceptName']);
            $this->assertSame('0612345678', $d['acceptMobile']);
            $this->assertSame('12 Rue Atlas, Mohammédia', $d['acceptAddress']);
            $this->assertSame('Casablanca - Settat', $d['acceptProvinceName']);
            $this->assertSame('MOHAMMEDIA', $d['acceptCityName']);
            $this->assertSame('Mohammedia', $d['acceptDistrictName']);
            $this->assertSame('Casablanca - Settat', $d['sendProvinceName']);
            $this->assertSame('0522000000', $d['sendMobile']);
            $this->assertEquals(250, $d['codFee']);
            $this->assertSame('MAD', $d['currencyType']);
            $this->assertSame(2, $d['goodsQTY']);
            $this->assertEquals(1, $d['parcelWeight']);
            $this->assertSame('Robe été', $d['itemList'][0]['goodsName']);
            $this->assertEquals(125, $d['itemList'][0]['goodsValue']);
            $this->assertMatchesRegularExpression('/^LF[0-9A-F]{4}-\d+$/', $d['customOrderNo']);
            foreach (['parcelType' => 'PT01', 'deliveryType' => 'DE01', 'transportType' => 'TT01', 'shipType' => 'ST01', 'payMethod' => 'PA02'] as $k => $v) {
                $this->assertSame($v, $d[$k]);
            }

            return true;
        });

        $shipment = SpeedafShipment::first();
        $this->assertSame('MA020000031836', $shipment->bill_code);
        $this->assertSame('created', $shipment->state);
        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('MA020000031836', $shipment->create_response['billCode']);
        $this->assertNotEmpty($shipment->request_payload['itemList']);

        // Listed with its tracking number in Commandes.
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('data.0.speedaf.bill_code', 'MA020000031836');
        $this->getJson('/api/orders?speedaf=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/orders?speedaf=0')->assertJsonCount(0, 'data');

        // Second send is refused.
        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]])->assertStatus(422)
            ->assertJsonPath('message', "La commande {$order->reference()} est déjà envoyée à Speedaf (n° MA020000031836).");
    }

    public function test_paid_order_has_no_cod(): void
    {
        $this->configure();
        $this->fakeApi();
        $order = $this->confirmedOrder(['payment_method' => 'paye']);

        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'createOrder') && json_decode($r->body(), true)['data']['codFee'] == 0);
    }

    public function test_send_requires_configuration_and_confirmed_order(): void
    {
        Http::fake();
        $order = $this->confirmedOrder();
        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Intégration Speedaf incomplète : activer l’intégration, App Code, Code client, nom expéditeur, téléphone expéditeur, adresse expéditeur, ville expéditeur (Intégrations → Speedaf).']);

        $this->configure();
        $pending = $this->order(['customer_name' => 'X', 'customer_phone' => '0600000000', 'city' => 'Rabat', 'amount' => 10]);
        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$pending->id]])->assertStatus(422)
            ->assertJsonPath('message', "La commande {$pending->reference()} doit être confirmée avant l’envoi à Speedaf.");
        Http::assertNothingSent();
    }

    public function test_api_error_is_reported_in_french(): void
    {
        $this->configure();
        $this->fakeApi([
            self::UAT.'express/order/v2/createOrder*' => Http::response(['success' => false, 'error' => ['code' => '500', 'message' => 'acceptMobile: Accept mobile is null!'], 'data' => null]),
        ]);
        $order = $this->confirmedOrder();

        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]])->assertStatus(422)
            ->assertJsonPath('message', 'Speedaf a refusé la demande : téléphone du destinataire manquant (code 500)');
        $this->assertSame(0, SpeedafShipment::count());
    }

    public function test_bulk_send_reports_each_order(): void
    {
        $this->configure();
        $this->fakeApi([
            self::UAT.'express/order/v2/createOrder*' => Http::sequence()->push($this->createResponse('MA1'))->push($this->createResponse('MA2')),
        ]);
        $a = $this->confirmedOrder();
        $b = $this->confirmedOrder(['customer_name' => 'Omar']);
        $c = $this->order(['customer_name' => 'Pas confirmé', 'customer_phone' => '0600000000', 'city' => 'Rabat', 'amount' => 10]);

        $res = $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$a->id, $b->id, $c->id]])->assertOk()
            ->assertJsonPath('sent', 2)->assertJsonPath('failed', 1);
        $this->assertStringContainsString('2 commande(s) envoyée(s)', $res->json('message'));
        $this->assertEqualsCanonicalizing(['MA1', 'MA2'], SpeedafShipment::pluck('bill_code')->all());
    }

    /* ------------------------------------------------------------------ cancel / label */

    public function test_cancel_shipment(): void
    {
        $this->configure();
        $this->fakeApi([
            self::UAT.'express/order/v2/cancelOrder*' => Http::response(['success' => true, 'error' => null, 'data' => [['billCode' => 'MA020000031836', 'success' => true]]]),
        ]);
        $order = $this->confirmedOrder();
        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]])->assertOk();

        $this->postJson("/api/speedaf/orders/{$order->id}/cancel", ['reason' => 'Client a annulé'])->assertOk()
            ->assertJsonPath('data.speedaf', null)
            ->assertJsonPath('message', 'Envoi Speedaf annulé.');

        Http::assertSent(function (HttpRequest $r) {
            if (! str_contains($r->url(), 'cancelOrder')) {
                return false;
            }
            $d = json_decode($r->body(), true)['data'];

            return $d[0]['billCode'] === 'MA020000031836' && $d[0]['customerCode'] === 'MA000025' && $d[0]['cancelReason'] === 'Client a annulé';
        });
        $this->assertSame('cancelled', SpeedafShipment::first()->state);

        // Can be sent again (new customOrderNo so Speedaf creates a new waybill).
        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]])->assertOk();
        $nos = SpeedafShipment::orderBy('id')->pluck('custom_order_no')->all();
        $this->assertNotSame($nos[0], $nos[1]);
        $this->assertStringEndsWith('-2', $nos[1]);
    }

    public function test_cancel_refused_by_speedaf(): void
    {
        $this->configure();
        $this->fakeApi([
            self::UAT.'express/order/v2/cancelOrder*' => Http::response(['success' => true, 'error' => null, 'data' => [['billCode' => 'MA020000031836', 'success' => false, 'message' => 'Order not exists']]]),
        ]);
        $order = $this->confirmedOrder();
        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]]);

        $this->postJson("/api/speedaf/orders/{$order->id}/cancel")->assertStatus(422)
            ->assertJsonPath('message', 'Speedaf a refusé l’annulation : Order not exists');
        $this->assertSame('created', SpeedafShipment::first()->state);
    }

    public function test_print_label_returns_pdf(): void
    {
        $this->configure(['label_type' => 46]);
        $pdf = "%PDF-1.4\n%fake label\n";
        $this->fakeApi([
            self::UAT.'express/order/v2/print*' => Http::response(['success' => true, 'data' => [
                'urls' => ['http://apis.speedaf.com/open-api/storage/waybill/2021/7/MA020000031836.pdf'],
                'orderLabels' => [['waybillNo' => 'MA020000031836', 'labelUrl' => 'http://apis.speedaf.com/open-api/storage/waybill/2021/7/MA020000031836.pdf', 'labelBase64' => base64_encode($pdf)]],
            ]]),
        ]);
        $order = $this->confirmedOrder();
        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]]);

        $res = $this->get("/api/speedaf/orders/{$order->id}/label")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame($pdf, $res->getContent());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'order/v2/print')
            && json_decode($r->body(), true)['data'] === ['waybillNoList' => ['MA020000031836'], 'labelType' => 46, 'withLogo' => true]);

        $this->postJson('/api/speedaf/labels', ['order_ids' => [$order->id, 999]])->assertOk()
            ->assertJsonPath('labels.0.bill_code', 'MA020000031836')
            ->assertJsonPath('labels.0.url', 'http://apis.speedaf.com/open-api/storage/waybill/2021/7/MA020000031836.pdf');
        $this->assertNotNull(SpeedafShipment::first()->label_url);
    }

    /* ------------------------------------------------------------------ tracking */

    protected function trackResponse(string $action, string $subAction, string $time, string $name = 'Delivered'): array
    {
        return ['success' => true, 'data' => [[
            'mailNo' => 'MA020000031836',
            'tracks' => [
                ['mailNo' => 'MA020000031836', 'action' => '1', 'subAction' => '1', 'actionName' => 'Pick Up', 'message' => 'Received', 'msgEng' => 'Parcel scanned by site', 'time' => '2026-10-01 09:00:00', 'msgLoc' => 'Parcel scanned by site', 'timezone' => 1],
                ['mailNo' => 'MA020000031836', 'action' => $action, 'subAction' => $subAction, 'actionName' => $name, 'message' => $name, 'msgEng' => $name.' msg', 'time' => $time, 'msgLoc' => $name.' msg', 'timezone' => 1],
            ],
        ]]];
    }

    public function test_tracking_sync_maps_status_and_command_runs(): void
    {
        $this->configure();
        $this->fakeApi([
            self::UAT.'express/track/v2/query*' => Http::sequence()
                ->push($this->trackResponse('4', '4', '2026-10-01 12:00:00', 'Out for Delivery'))
                ->push($this->trackResponse('5', '5', '2026-10-01 15:00:00')),
        ]);
        $order = $this->confirmedOrder();
        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]]);

        $this->postJson("/api/speedaf/orders/{$order->id}/sync")->assertOk()
            ->assertJsonPath('data.delivery_status_code', 'in_progress')
            ->assertJsonPath('data.speedaf.status_code', '4');

        $this->artisan('speedaf:sync')->assertSuccessful();
        $order->refresh();
        $this->assertSame('delivered', $order->delivery_status);
        $this->assertEquals(250, $order->amount_collected);
        $this->assertSame('delivered', SpeedafShipment::first()->state);
        $this->assertCount(3, SpeedafShipment::first()->tracks); // pick up + out for delivery + delivered (merged, deduped)
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'status_code' => 'delivered']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'track/v2/query') && json_decode($r->body(), true)['data'] === ['mailNoList' => ['MA020000031836']]);

        // Delivered shipments are no longer polled.
        Http::fake();
        $this->artisan('speedaf:sync')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_problem_code_maps_with_reason_and_ignored_codes_keep_status(): void
    {
        $this->configure(['status_mapping' => array_replace(SpeedafStatusMap::DEFAULT_MAPPING, ['1' => null])]);
        $this->fakeApi([
            self::UAT.'express/track/v2/query*' => Http::response($this->trackResponse('IP05', 'IP05-04', '2026-10-01 12:00:00', 'Not Interested')),
        ]);
        $order = $this->confirmedOrder();
        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]]);

        $this->postJson("/api/speedaf/orders/{$order->id}/sync")->assertOk();
        $order->refresh();
        $this->assertSame('failed', $order->delivery_status);
        $this->assertSame('Not Interested msg', $order->delivery_failure_reason);
        $this->assertSame('Refusé par le destinataire', SpeedafShipment::first()->last_action_name);
    }

    /* ------------------------------------------------------------------ webhook */

    public function test_webhook_with_valid_signature_updates_order_and_is_idempotent(): void
    {
        $settings = $this->configure(['secret_key' => 'test-secret-key']);
        $this->fakeApi();
        $order = $this->confirmedOrder();
        $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]]);
        $this->app['auth']->forgetGuards();

        $body = str_replace('86254200001257', 'MA020000031836', SpeedafSupportTest::WEBHOOK_BODY);
        $ts = (string) (int) floor(microtime(true) * 1000);
        $headers = [
            'X-Speedaf-App-Code' => 'MA000025', 'X-Speedaf-Timestamp' => $ts, 'X-Speedaf-Event-Type' => 'TRACK_UPDATE',
            'X-Speedaf-Event-Id' => 'CN000001_MA020000031836_xxxxx', 'X-Speedaf-Signature' => SpeedafClient::webhookSignature('test-secret-key', $ts, $body),
            'Content-Type' => 'application/json',
        ];
        $url = '/speedaf/webhook/'.$settings->webhook_token;

        $this->call('POST', $url, [], [], [], $this->serverHeaders($headers), $body)
            ->assertOk()->assertExactJson(['success' => true, 'error' => null, 'data' => null]);

        $order->refresh();
        $this->assertSame('in_progress', $order->delivery_status); // action 1 (pick up) → En cours
        $this->assertSame('1', SpeedafShipment::first()->last_action);
        $this->assertDatabaseCount('speedaf_webhook_events', 1);

        // Retry of the same event: acknowledged, not re-applied.
        $this->call('POST', $url, [], [], [], $this->serverHeaders($headers), $body)->assertOk();
        $this->assertDatabaseCount('speedaf_webhook_events', 1);

        // Bad signature / unknown token.
        $bad = array_merge($headers, ['X-Speedaf-Signature' => 'hmac-sha256=deadbeef']);
        $this->call('POST', $url, [], [], [], $this->serverHeaders($bad), $body)->assertStatus(401);
        $this->call('POST', '/speedaf/webhook/'.str_repeat('a', 40), [], [], [], $this->serverHeaders($headers), $body)->assertStatus(404);

        // Expired timestamp (replay).
        $old = (string) ((int) $ts - 600000);
        $replay = array_merge($headers, ['X-Speedaf-Timestamp' => $old, 'X-Speedaf-Signature' => SpeedafClient::webhookSignature('test-secret-key', $old, $body)]);
        $this->call('POST', $url, [], [], [], $this->serverHeaders($replay), $body)->assertStatus(401);
    }

    public function test_webhook_subscription_call(): void
    {
        $settings = $this->configure();
        Http::fake([self::UAT.'express/track/webhook/subscribe*' => Http::response(['success' => true, 'error' => null, 'data' => ['customerCode' => 'MA000025', 'callbackUrl' => 'x', 'appCode' => 'MA000025']])]);

        $this->postJson('/api/integrations/speedaf/webhook/subscribe')->assertOk()
            ->assertJsonPath('message', 'Webhook Speedaf enregistré : les statuts seront mis à jour automatiquement.');
        Http::assertSent(fn (HttpRequest $r) => json_decode($r->body(), true)['data'] === [
            'customerCode' => 'MA000025', 'callbackUrl' => $settings->webhookUrl(), 'appCode' => 'MA000025',
        ]);
        $this->assertNotNull(SpeedafSetting::first()->webhook_subscribed_at);
    }

    public function test_connection_errors_are_french(): void
    {
        $this->configure();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->postJson('/api/integrations/speedaf/test')->assertStatus(422)
            ->assertJsonPath('message', 'Impossible de joindre l’API Speedaf (https://uat-api.speedaf.com/). Vérifiez la connexion Internet du serveur ou réessayez plus tard.');
    }

    protected function serverHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $key = strtoupper(str_replace('-', '_', $k));
            $out[in_array($key, ['CONTENT_TYPE'], true) ? $key : 'HTTP_'.$key] = $v;
        }

        return $out;
    }
}
