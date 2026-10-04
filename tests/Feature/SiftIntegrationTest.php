<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Setting;
use App\Models\SiftApiLog;
use App\Models\SiftSetting;
use App\Models\SiftShipment;
use App\Models\SiftWebhookEvent;
use App\Models\SpeedafSetting;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** T8 — Sift.ma carrier (every API call faked: no key yet). */
class SiftIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected const KEY = 'sift_live_SECRET_a1b2c3d4e5';

    protected const API = 'https://apis.sift.ma/v1';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signInAdmin();
        Http::preventStrayRequests();
    }

    protected function configure(array $extra = []): SiftSetting
    {
        $s = SiftSetting::forCompany($this->admin->resolveCompanyId());
        $s->fill(['enabled' => true] + $extra);
        $s->api_key = self::KEY;
        $s->save();
        app()->forgetInstance(CarrierRegistry::class);

        return $s->fresh();
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

    protected function parcel(array $over = []): array
    {
        return $over + [
            'id' => 'prc_001', 'trackingNumber' => 'SFT100200300', 'customOrderNo' => 'X', 'status' => 'pending',
            'customerName' => 'Fatima Zahra', 'customerPhone' => '0612345678', 'city' => 'Rabat', 'address' => '12 Rue Atlas, Agdal', 'codAmount' => 250,
        ];
    }

    protected function sendOne(Order $order, array $parcel = [], int $status = 201): void
    {
        Http::fake([self::API.'/parcels' => Http::response(['success' => true, 'data' => $this->parcel($parcel + ['customOrderNo' => ltrim($order->reference(), '#')])], $status)]);
        $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$order->id]])->assertOk();
    }

    protected function assertNoKeyAnywhere(): void
    {
        foreach (['sift_api_logs', 'sift_shipments', 'sift_webhook_events', 'order_status_histories', 'settings'] as $table) {
            $this->assertStringNotContainsString(self::KEY, json_encode(DB::table($table)->get()), "API key leaked in {$table}");
        }
        $this->assertStringNotContainsString(self::KEY, (string) DB::table('sift_settings')->value('api_key'), 'API key stored in clear');
        $log = storage_path('logs/laravel.log');
        if (is_file($log)) {
            $this->assertStringNotContainsString(self::KEY, (string) file_get_contents($log));
        }
    }

    protected function signed(SiftSetting $s, array $payload, array $headers = []): TestResponse
    {
        $raw = json_encode($payload);
        $sig = hash_hmac('sha256', $raw, (string) $s->webhook_secret);

        return $this->call('POST', '/sift/webhook/'.$s->webhook_token, [], [], [], $this->server($headers + ['X-Sift-Signature' => 'sha256='.$sig]), $raw);
    }

    protected function server(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $k => $v) {
            $out['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $out;
    }

    /* ------------------------------------------------------------------ settings */

    public function test_settings_keep_the_key_encrypted_and_write_only(): void
    {
        $this->putJson('/api/integrations/sift', ['enabled' => true])->assertStatus(422);
        $res = $this->putJson('/api/integrations/sift', ['enabled' => true, 'api_key' => self::KEY, 'auth_mode' => 'bearer', 'waybill_format' => 'A4'])->assertOk();
        $this->assertStringNotContainsString(self::KEY, $res->getContent());
        $res->assertJsonPath('data.has_api_key', true)->assertJsonPath('data.api_key_hint', '••••••••'.substr(self::KEY, -4))
            ->assertJsonPath('data.base_url', self::API)->assertJsonPath('data.bulk_enabled', false)->assertJsonPath('data.waybill_format', 'A4');
        $this->assertStringContainsString('/sift/webhook/', $res->json('data.webhook.url'));
        $this->assertStringNotContainsString((string) SiftSetting::first()->webhook_secret, $this->getJson('/api/integrations/sift')->getContent());
        $this->assertSame(SiftSetting::first()->webhook_secret, $this->postJson('/api/integrations/sift/webhook/secret')->assertOk()->json('secret'));
        $this->assertSame(self::KEY, SiftSetting::first()->api_key);
        $this->assertNoKeyAnywhere();
        $this->assertFalse((bool) Setting::getValue('sift_bulk_enabled', false));
    }

    public function test_connection_test_sends_the_key_as_bearer_or_x_api_key(): void
    {
        $this->configure();
        Http::fake([self::API.'/parcels*' => Http::response(['success' => true, 'data' => [], 'pagination' => ['page' => 1, 'limit' => 1, 'total' => 12]])]);
        $this->postJson('/api/integrations/sift/test')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('data.state', 'connecte');
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer '.self::KEY) && str_contains($r->url(), '/parcels?'));

        $this->putJson('/api/integrations/sift', ['auth_mode' => 'x-api-key'])->assertOk();
        $this->postJson('/api/integrations/sift/test')->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-API-Key', self::KEY) && ! $r->hasHeader('Authorization'));
    }

    public function test_wrong_key_is_reported_without_leaking_it(): void
    {
        $this->configure();
        Http::fake([self::API.'/parcels*' => Http::response(['success' => false, 'error' => 'unauthorized', 'message' => 'Invalid or missing API key. Provide a valid API key in the Authorization header (Bearer token) or X-API-Key header.'], 401)]);
        $res = $this->postJson('/api/integrations/sift/test')->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('clé API refusée', $res->json('message'));
        $this->assertStringContainsString('(Bearer token)', $res->json('message'));
        $this->assertSame(1, SiftApiLog::where('action', 'test')->count());
        $this->assertNoKeyAnywhere();
    }

    /* ------------------------------------------------------------------ send */

    public function test_send_builds_the_parcel_and_saves_parcel_id_tracking_and_custom_order_no(): void
    {
        $this->configure(['items_mode' => 'sku', 'send_note' => true, 'default_allow_open' => true]);
        $order = $this->confirmedOrder();
        $no = ltrim($order->reference(), '#');

        $preview = $this->postJson('/api/carriers/sift/preview', ['order_ids' => [$order->id]])->assertOk();
        $preview->assertJsonPath('rows.0.can_send', true)->assertJsonPath('rows.0.custom_order_no', $no)->assertJsonPath('rows.0.quantity', 3);
        Http::assertNothingSent();

        Http::fake([self::API.'/parcels' => Http::response(['success' => true, 'data' => $this->parcel(['customOrderNo' => $no])], 201)]);
        $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$order->id]])->assertOk()
            ->assertJsonPath('sent', 1)->assertJsonPath('results.0.tracking', 'SFT100200300')
            ->assertJsonPath('data.shipment.carrier', 'sift')->assertJsonPath('data.shipment.carrier_label', 'Sift')
            ->assertJsonPath('data.carrier', 'Sift');

        Http::assertSent(function (HttpRequest $r) use ($no) {
            $d = $r->data();

            return $r->method() === 'POST' && $r->url() === self::API.'/parcels' && $r->hasHeader('Authorization', 'Bearer '.self::KEY)
                && $d['customOrderNo'] === $no && $d['customerName'] === 'Fatima Zahra' && $d['customerPhone'] === '0612345678'
                && $d['city'] === 'Rabat' && $d['address'] === '12 Rue Atlas, Agdal' && $d['codAmount'] == 250 && $d['price'] == 250 && $d['cod'] === true
                && $d['allowOpen'] === true && $d['notes'] === 'Appeler avant' && $d['quantity'] === 3
                && $d['items'] === [['sku' => 'ROBE-M', 'name' => 'Robe', 'quantity' => 2, 'price' => 100.0], ['name' => 'Ceinture', 'quantity' => 1, 'price' => 50.0]];
        });

        $s = SiftShipment::firstOrFail();
        $this->assertSame(['prc_001', 'SFT100200300', $no, 'pending', false], [$s->parcel_id, $s->tracking_number, $s->custom_order_no, $s->raw_status, $s->reused_existing]);
        $this->assertSame(['sift_sent', 'sift_tracking'], OrderStatusHistory::where('order_id', $order->id)->where('kind', 'sift')->orderBy('id')->pluck('status_code')->all());
        $list = collect($this->getJson('/api/orders?per_page=50')->assertOk()->json('data'))->firstWhere('id', $order->id);
        $this->assertSame('Sift', $list['shipment']['carrier_label']);
        $this->getJson('/api/orders?carrier=sift')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/orders/'.$order->id)->assertOk()->assertJsonPath('data.sift.tracking_number', 'SFT100200300');
        $this->assertSame('Déjà envoyée à Sift.', $order->fresh()->localAssignmentBlocker());
        $this->assertNoKeyAnywhere();
    }

    public function test_manual_items_mode_never_sends_skus_and_validation_blocks(): void
    {
        $this->configure();
        $order = $this->confirmedOrder();
        $row = $this->postJson('/api/carriers/sift/preview', ['order_ids' => [$order->id]])->json('rows.0');
        $this->assertSame([['name' => 'Robe', 'quantity' => 2, 'price' => 100], ['name' => 'Ceinture', 'quantity' => 1, 'price' => 50]], $row['items']);

        $order->forceFill(['confirmation_status' => 'pending'])->save();
        $row = $this->postJson('/api/carriers/sift/preview', ['order_ids' => [$order->id]])->json('rows.0');
        $this->assertFalse($row['can_send']);
        $this->assertStringContainsString('confirmée', implode(' ', $row['errors']));
        Http::assertNothingSent();
    }

    public function test_local_anti_duplicate_and_lock(): void
    {
        $this->configure();
        $order = $this->confirmedOrder();
        $lock = Cache::lock('sift-parcel-'.$order->id, 60);
        $this->assertTrue($lock->get());
        $res = $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$order->id]])->assertStatus(422);
        $this->assertStringContainsString('déjà en cours', $res->json('results.0.message'));
        Http::assertNothingSent();
        $lock->release();

        $this->sendOne($order);
        $res = $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$order->id]])->assertStatus(422);
        $this->assertStringContainsString('déjà envoyée à Sift', $res->json('results.0.message'));
        Http::assertSentCount(1);
        $this->assertSame(1, SiftShipment::count());
    }

    public function test_existing_custom_order_no_returns_the_existing_parcel(): void
    {
        $this->configure();
        $order = $this->confirmedOrder();
        // Stubs are matched first-registered-first: one sequence for both sends.
        Http::fake([self::API.'/parcels' => Http::sequence()
            ->push(['success' => true, 'existing' => true, 'data' => $this->parcel(['id' => 'prc_old', 'trackingNumber' => 'SFTOLD', 'status' => 'in_transit'])], 200)
            ->push(['success' => false, 'error' => 'duplicate', 'data' => $this->parcel(['id' => 'prc_409', 'trackingNumber' => 'SFT409'])], 409)]);
        $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$order->id]])->assertOk()->assertJsonPath('results.0.tracking', 'SFTOLD');
        $s = SiftShipment::firstOrFail();
        $this->assertTrue($s->reused_existing);
        $this->assertSame('in_progress', $order->fresh()->delivery_status);
        $this->assertStringContainsString('colis existant', OrderStatusHistory::where('status_code', 'sift_sent')->value('status_name'));

        // 409 carrying the parcel = same thing.
        $other = $this->confirmedOrder(['customer_name' => 'Karim']);
        $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$other->id]])->assertOk()->assertJsonPath('results.0.tracking', 'SFT409');
        $this->assertTrue(SiftShipment::where('parcel_id', 'prc_409')->value('reused_existing'));
    }

    public function test_timeout_resyncs_by_custom_order_no_instead_of_duplicating(): void
    {
        $this->configure();
        $order = $this->confirmedOrder();
        $no = ltrim($order->reference(), '#');
        Http::fake([
            self::API.'/parcels?*' => Http::response(['success' => true, 'data' => [$this->parcel(['customOrderNo' => $no, 'id' => 'prc_lost', 'trackingNumber' => 'SFTLOST'])], 'pagination' => ['total' => 1]]),
            self::API.'/parcels' => fn () => throw new ConnectionException('Operation timed out'),
        ]);
        $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$order->id]])->assertOk()->assertJsonPath('results.0.tracking', 'SFTLOST');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'customOrderNo='.$no));
        $this->assertSame(1, SiftShipment::count());
    }

    /* ------------------------------------------------------------------ edit / cancel / hide */

    public function test_edit_only_while_pending(): void
    {
        $this->configure();
        $order = $this->confirmedOrder();
        $this->sendOne($order);
        Http::fake([self::API.'/parcels/prc_001' => Http::response(['success' => true, 'data' => $this->parcel(['city' => 'Salé'])])]);
        $this->putJson('/api/sift/orders/'.$order->id, ['city' => 'Salé', 'phone' => '06 11 22 33 44', 'cod_amount' => 199, 'notes' => 'Après 18h'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->url() === self::API.'/parcels/prc_001'
            && $r->data() === ['customerPhone' => '0611223344', 'city' => 'Salé', 'codAmount' => 199.0, 'notes' => 'Après 18h']);
        $this->assertSame(['Salé', 199.0], [SiftShipment::first()->city, (float) SiftShipment::first()->cod_amount]);

        SiftShipment::first()->forceFill(['raw_status' => 'in_transit'])->save();
        $res = $this->putJson('/api/sift/orders/'.$order->id, ['city' => 'Rabat'])->assertStatus(422);
        $this->assertStringContainsString('En attente', $res->json('message'));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PUT' && ($r->data()['city'] ?? null) === 'Rabat');
    }

    public function test_cancel_is_a_put_status_cancelled_and_hide_is_separate(): void
    {
        $this->configure();
        $order = $this->confirmedOrder();
        $this->sendOne($order);

        // Hide refused while active.
        $this->postJson('/api/sift/orders/'.$order->id.'/hide')->assertStatus(422)->assertJsonFragment(['message' => 'Colis encore actif : utilisez d’abord « Annuler chez le transporteur ». La suppression Sift ne l’annule pas chez le livreur.']);

        Http::fake([self::API.'/parcels/prc_001' => Http::sequence()
            ->push(['success' => true, 'data' => $this->parcel(['status' => 'cancelled'])])
            ->push(['success' => true, 'message' => 'deleted'])]);
        $this->postJson('/api/sift/orders/'.$order->id.'/cancel', ['reason' => 'Client a annulé'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->url() === self::API.'/parcels/prc_001' && $r->data() === ['status' => 'cancelled']);
        $s = SiftShipment::first();
        $this->assertSame(SiftShipment::STATE_CANCELLED, $s->state);
        $this->assertNull($order->fresh()->carrier);
        $this->assertNull($order->fresh()->localAssignmentBlocker() === 'Déjà envoyée à Sift.' ? 'blocked' : null);

        $this->postJson('/api/sift/orders/'.$order->id.'/hide', ['shipment_id' => $s->id])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/parcels/prc_001');
        $this->assertNotNull(SiftShipment::first()->hidden_at);
        $this->assertSame(['sift_sent', 'sift_tracking', 'sift_cancelled', 'sift_hidden'], OrderStatusHistory::where('kind', 'sift')->orderBy('id')->pluck('status_code')->all());
    }

    public function test_resend_after_cancel_uses_a_suffixed_custom_order_no(): void
    {
        $this->configure();
        $order = $this->confirmedOrder();
        $no = ltrim($order->reference(), '#');
        $this->sendOne($order);
        SiftShipment::first()->forceFill(['state' => SiftShipment::STATE_CANCELLED])->save();
        Http::fake([self::API.'/parcels' => Http::response(['success' => true, 'data' => $this->parcel(['id' => 'prc_002', 'trackingNumber' => 'SFT2', 'customOrderNo' => $no.'-R2'])], 201)]);
        $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$order->id]])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && ($r->data()['customOrderNo'] ?? null) === $no.'-R2');
        $this->assertSame(2, SiftShipment::count());
    }

    /* ------------------------------------------------------------------ waybill / labels */

    public function test_waybill_is_proxied_with_the_chosen_format(): void
    {
        $this->configure();
        $order = $this->confirmedOrder();
        $this->sendOne($order);
        Http::fake([self::API.'/parcels/prc_001/waybill*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf'])]);
        $res = $this->get('/api/sift/orders/'.$order->id.'/waybill?format=THERMAL_150x100')->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('%PDF-1.4 fake', $res->getContent());
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), self::API.'/parcels/prc_001/waybill?format=THERMAL_150x100'));
        $this->getJson('/api/sift/orders/'.$order->id.'/waybill?format=BAD')->assertStatus(422);
    }

    public function test_bulk_actions_are_gated_by_sift_bulk_enabled(): void
    {
        $this->configure();
        $a = $this->confirmedOrder();
        $b = $this->confirmedOrder(['customer_name' => 'Karim']);
        $carrier = collect($this->getJson('/api/carriers')->json('carriers'))->firstWhere('key', 'sift');
        $this->assertTrue($carrier['available']);
        $this->assertFalse($carrier['bulk_enabled']);
        $this->assertArrayHasKey('A4', $carrier['waybill_formats']);
        $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$a->id, $b->id]])->assertStatus(422);
        $this->postJson('/api/sift/labels', ['order_ids' => [$a->id, $b->id]])->assertStatus(422);
        Http::assertNothingSent();

        Setting::setValue('sift_bulk_enabled', true);
        Http::fake([self::API.'/parcels' => Http::sequence()
            ->push(['success' => true, 'data' => $this->parcel(['id' => 'p1', 'trackingNumber' => 'T1'])], 201)
            ->push(['success' => true, 'data' => $this->parcel(['id' => 'p2', 'trackingNumber' => 'T2'])], 201)]);
        $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$a->id, $b->id]])->assertOk()->assertJsonPath('sent', 2);
        $labels = $this->postJson('/api/sift/labels', ['order_ids' => [$a->id, $b->id], 'format' => 'A4'])->assertOk()->json('labels');
        $this->assertCount(2, $labels);
        $this->assertStringEndsWith('/waybill?format=A4', $labels[0]['pdf_url']);
    }

    /* ------------------------------------------------------------------ errors / retry */

    public function test_api_error_is_logged_redacted_and_can_be_retried(): void
    {
        $this->configure();
        $order = $this->confirmedOrder();
        Http::fake([self::API.'/parcels' => Http::sequence()
            ->push(['success' => false, 'error' => 'validation_error', 'message' => 'city is required (key '.self::KEY.')'], 422)
            ->push(['success' => true, 'data' => $this->parcel()], 201)]);
        $res = $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$order->id]])->assertStatus(422);
        $this->assertStringNotContainsString(self::KEY, $res->getContent());
        $log = SiftApiLog::firstOrFail();
        $this->assertSame(['create_parcel', 422, 'POST'], [$log->action, $log->http_status, $log->method]);
        $this->assertNoKeyAnywhere();

        $this->getJson('/api/integrations/sift/logs')->assertOk()->assertJsonPath('data.0.retryable', true);
        $this->postJson('/api/integrations/sift/logs/'.$log->id.'/retry')->assertOk();
        $this->assertTrue($log->fresh()->resolved);
        $this->assertSame(1, SiftShipment::count());
    }

    /* ------------------------------------------------------------------ webhooks / sync */

    public function test_webhook_signature_mapping_timeline_and_idempotency(): void
    {
        $s = $this->configure();
        $order = $this->confirmedOrder();
        $this->sendOne($order);
        $payload = ['id' => 'evt_1', 'event' => 'parcel.status_changed', 'data' => ['parcel' => ['id' => 'prc_001', 'trackingNumber' => 'SFT100200300', 'status' => 'out_for_delivery', 'subStatus' => 'with_driver']]];

        // Bad / missing signature → 401 + rejected row with header names only.
        $raw = json_encode($payload);
        $this->call('POST', '/sift/webhook/'.$s->webhook_token, [], [], [], $this->server(['X-Sift-Signature' => 'sha256=deadbeef']), $raw)->assertStatus(401);
        $this->call('POST', '/sift/webhook/'.$s->webhook_token, [], [], [], $this->server([]), $raw)->assertStatus(401);
        $this->assertSame(2, SiftWebhookEvent::where('status', 'rejected')->count());
        $this->assertStringNotContainsString('deadbeef', json_encode(SiftWebhookEvent::all()));
        $this->call('POST', '/sift/webhook/unknownTokenXXXXXXXXXXXXXXXX', [], [], [], $this->server([]), $raw)->assertStatus(404);
        $this->assertSame('to_assign', $order->fresh()->delivery_status);

        $this->signed($s, $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame('in_progress', $order->fresh()->delivery_status);
        $ship = SiftShipment::first();
        $this->assertSame(['out_for_delivery', 'with_driver', 'in_progress'], [$ship->raw_status, $ship->raw_sub_status, $ship->mapped_status]);
        $this->assertSame(1, OrderStatusHistory::where('status_code', 'sift_status')->count());

        // Same event again → no new timeline entry.
        $this->signed($s, $payload)->assertOk();
        $this->assertSame(1, OrderStatusHistory::where('status_code', 'sift_status')->count());
        $this->assertSame(1, SiftWebhookEvent::where('status', 'processed')->count());

        // parcel.delivered (Stripe-like signature) → delivered + COD collected.
        $payload2 = ['id' => 'evt_2', 'event' => 'parcel.delivered', 'data' => ['parcelId' => 'prc_001', 'trackingNumber' => 'SFT100200300']];
        $raw2 = json_encode($payload2);
        $t = time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$raw2, (string) $s->webhook_secret);
        $this->call('POST', '/sift/webhook/'.$s->webhook_token, [], [], [], $this->server(['X-Webhook-Signature' => $sig]), $raw2)->assertOk();
        $this->assertSame('delivered', $order->fresh()->delivery_status);
        $this->assertSame(SiftShipment::STATE_DELIVERED, SiftShipment::first()->state);

        // Unknown parcel → ignored, 200.
        $this->signed($s, ['id' => 'evt_3', 'event' => 'return.status_changed', 'data' => ['return' => ['parcelId' => 'nope', 'status' => 'returned']]])->assertOk();
        $this->assertSame('ignored', SiftWebhookEvent::where('event_id', 'evt_3')->value('status'));
        $this->assertNotNull($s->fresh()->webhook_last_received_at);
    }

    public function test_unknown_status_is_kept_raw_and_mapping_is_configurable(): void
    {
        $s = $this->configure();
        $order = $this->confirmedOrder();
        $this->sendOne($order);
        Http::fake([self::API.'/parcels/prc_001' => Http::sequence()
            ->push(['success' => true, 'data' => $this->parcel(['status' => 'stuck_at_hub'])])
            ->push(['success' => true, 'data' => $this->parcel(['status' => 'stuck_at_hub', 'subStatus' => 'retry'])])]);
        $this->postJson('/api/sift/orders/'.$order->id.'/refresh')->assertOk();
        $this->assertSame('stuck_at_hub', SiftShipment::first()->raw_status);
        $this->assertSame('to_assign', $order->fresh()->delivery_status);
        $row = collect($this->getJson('/api/integrations/sift')->json('data.status_mapping'))->firstWhere('raw', 'stuck_at_hub');
        $this->assertTrue($row['seen']);
        $this->assertNull($row['code']);

        $this->putJson('/api/integrations/sift', ['status_mapping' => [['raw' => 'stuck_at_hub', 'code' => 'in_progress'], ['raw' => 'delivered', 'code' => 'delivered']]])->assertOk();
        $this->postJson('/api/sift/orders/'.$order->id.'/refresh')->assertOk();
        $this->assertSame('in_progress', $order->fresh()->delivery_status);
        $this->assertSame('in_progress', SiftShipment::first()->mapped_status);
    }

    public function test_scheduled_polling_refreshes_open_parcels(): void
    {
        $this->configure(['auto_sync' => true]);
        $order = $this->confirmedOrder();
        $this->sendOne($order);
        Http::fake([self::API.'/parcels/prc_001' => Http::response(['success' => true, 'data' => $this->parcel(['status' => 'injoignable'])])]);
        Artisan::call('sift:sync');
        $this->assertSame('no_answer', $order->fresh()->delivery_status);
        $this->assertNotNull(SiftSetting::first()->last_synced_at);
    }

    public function test_control_list_lookup_and_products(): void
    {
        $this->configure();
        $order = $this->confirmedOrder();
        $this->sendOne($order);
        Http::fake([
            self::API.'/parcels/tracking/*' => Http::response(['success' => true, 'data' => $this->parcel()]),
            self::API.'/parcels?*' => Http::response(['success' => true, 'data' => [$this->parcel(), $this->parcel(['id' => 'prc_x', 'trackingNumber' => 'SFTX'])], 'pagination' => ['page' => 1, 'limit' => 20, 'total' => 2]]),
            self::API.'/products*' => Http::response(['success' => true, 'data' => [['id' => 'p1', 'sku' => 'ROBE-M', 'name' => 'Robe', 'stock' => 7]]]),
        ]);
        $res = $this->getJson('/api/integrations/sift/parcels?status=pending&city=Rabat&search=Fatima&page=1')->assertOk();
        $this->assertSame($order->id, $res->json('data.0.order.id'));
        $this->assertNull($res->json('data.1.order'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'status=pending') && str_contains($r->url(), 'city=Rabat') && str_contains($r->url(), 'search=Fatima'));
        $this->getJson('/api/integrations/sift/lookup?tracking=SFT100200300')->assertOk()->assertJsonPath('data.order.id', $order->id);
        $this->getJson('/api/integrations/sift/products?q=robe')->assertOk()->assertJsonPath('data.0.sku', 'ROBE-M')->assertJsonPath('data.0.stock', 7);
    }

    public function test_sift_parcel_blocks_speedaf_and_local_delivery(): void
    {
        $this->configure();
        SpeedafSetting::forCompany($this->admin->resolveCompanyId())->fill([
            'enabled' => true, 'environment' => 'uat', 'app_code' => 'MA000025', 'customer_code' => 'MA000025', 'platform_source' => 'TEST',
            'sender_name' => 'Lavfast', 'sender_mobile' => '0522000000', 'sender_address' => 'Bd Zerktouni', 'sender_city' => 'Casablanca',
        ])->save();
        $order = $this->confirmedOrder();
        $this->sendOne($order);
        $res = $this->postJson('/api/speedaf/orders/send', ['order_ids' => [$order->id]])->assertStatus(422);
        $this->assertStringContainsString('déjà envoyée à Sift', $res->json('message'));
        Http::assertNotSent(fn (HttpRequest $r) => ! str_starts_with($r->url(), self::API));
        $this->assertSame('Déjà envoyée à Sift.', $order->fresh()->localAssignmentBlocker());
        $this->postJson('/api/carriers/ozon/ship', ['order_ids' => [$order->id]])->assertStatus(422);
    }

    public function test_other_carriers_stay_available_and_unconfigured_sift_is_unavailable(): void
    {
        $carriers = collect($this->getJson('/api/carriers')->assertOk()->json('carriers'));
        $this->assertSame(['speedaf', 'ozon', 'sift'], $carriers->pluck('key')->all());
        $this->assertFalse($carriers->firstWhere('key', 'sift')['available']);
        $this->assertStringContainsString('Sift', $carriers->firstWhere('key', 'sift')['reason']);
        $order = $this->confirmedOrder();
        $this->postJson('/api/carriers/sift/ship', ['order_ids' => [$order->id]])->assertStatus(422);
        Http::assertNothingSent();
        $this->assertNull($order->fresh()->localAssignmentBlocker());
    }
}
