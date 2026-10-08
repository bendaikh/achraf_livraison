<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Driver;
use App\Models\Mission;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\OzonSetting;
use App\Models\SiftSetting;
use App\Models\SpeedafSetting;
use App\Models\SpeedafShipment;
use App\Models\User;
use App\Services\ClosingService;
use App\Services\OrderWorkflow;
use App\Services\Ozon\OzonShipmentService;
use App\Services\Sift\SiftShipmentService;
use App\Services\Speedaf\SpeedafShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Section E — À encaisser = montant restant dû, partout. */
class AmountDueTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Card-paid Shopify order (outstanding 0) → À encaisser 0, COD 0, no driver cash. */
    public function test_card_paid_order_collects_zero_everywhere(): void
    {
        $order = $this->shopifyOrder([
            'financial_status' => 'paid',
            'total_price' => 450,
            'total_outstanding' => 0,
            'amount_paid' => 450,
            'payment_gateway_names' => ['shopify_payments'],
        ]);

        $this->assertEquals(0, $order->amountDue());
        $this->assertFalse($order->isCod());
        $this->assertSame('Payée en ligne', $order->paymentLabel());
        $this->assertEquals(0, $this->speedafCod($order));
        $this->assertEquals(0, $this->siftPrice($order));
        $this->assertEquals(0, $this->ozonPrice($order));

        $mission = $this->assign($order);
        $this->assertNull($mission->cash_amount);
        $this->assertNull($mission->cash_direction);
    }

    /** Partially paid → the remaining amount on carriers, the driver mission and the closing. */
    public function test_partially_paid_order_collects_the_remainder(): void
    {
        $order = $this->shopifyOrder([
            'financial_status' => 'partially_paid',
            'total_price' => 450,
            'total_outstanding' => 350,
            'amount_paid' => 100,
        ]);

        $this->assertEquals(350, $order->amountDue());
        $this->assertSame('Partiellement payée', $order->paymentLabel());
        $this->assertEquals(350, $this->speedafCod($order));
        $this->assertEquals(350, $this->siftPrice($order));
        $this->assertEquals(350, $this->ozonPrice($order));

        $mission = $this->assign($order);
        $this->assertEquals(350, (float) $mission->cash_amount);

        $delivered = app(OrderWorkflow::class)->changeStatus(
            $order->fresh(),
            \App\Models\DeliveryStatus::query()->where('category', 'succes')->where('is_active', true)->first(),
            ['collected_amount' => $order->amountDue()],
        );
        $this->assertEquals(350, (float) $delivered->amount_collected);
        $pending = app(ClosingService::class)->pending($order->driver_id);
        $this->assertEquals(350, $pending['cod']);
    }

    public function test_cod_order_collects_the_total(): void
    {
        $order = $this->manual(['amount' => 450, 'payment_method' => 'cod']);
        $this->assertEquals(450, $order->amountDue());
        $this->assertSame('Paiement à la livraison', $order->paymentLabel());
        $this->assertTrue($order->isCod());
    }

    /** Paid 300 DH, then Shopify edits the order so 150 DH remains. */
    public function test_paid_order_edited_up_exposes_the_new_outstanding(): void
    {
        $order = $this->shopifyOrder([
            'financial_status' => 'paid',
            'total_price' => 300,
            'total_outstanding' => 0,
            'amount_paid' => 300,
        ]);
        $this->assertEquals(0, $order->amountDue());

        $order->forceFill([
            'total_price' => 450,
            'total_outstanding' => 150,
            'amount_paid' => 300,
            'financial_status' => 'partially_paid',
        ])->save();

        $this->assertEquals(150, $order->fresh()->amountDue());
    }

    public function test_amount_changed_after_parcel_warns_and_does_not_call_the_carrier(): void
    {
        $order = $this->shopifyOrder([
            'financial_status' => 'pending',
            'total_price' => 300,
            'total_outstanding' => 300,
            'amount_paid' => 0,
        ]);
        SpeedafShipment::create([
            'order_id' => $order->id,
            'company_id' => $order->company_id,
            'bill_code' => 'MA-TEST',
            'state' => 'created',
            'request_payload' => ['codFee' => 300],
        ]);

        $order->forceFill(['total_price' => 450, 'total_outstanding' => 450])->save();

        $this->assertTrue($order->fresh()->amount_due_stale);
        $this->assertTrue(
            OrderStatusHistory::query()->where('order_id', $order->id)
                ->where('kind', 'expedition')
                ->where('note', 'Montant à encaisser modifié après l’envoi — vérifier le colis')
                ->exists()
        );
    }

    public function test_manual_orders_paid_is_zero_and_cod_is_the_total(): void
    {
        $paid = $this->manual(['amount' => 450, 'payment_method' => 'paye']);
        $cod = $this->manual(['amount' => 450, 'payment_method' => 'cod']);

        $this->assertEquals(0, $paid->amountDue());
        $this->assertEquals(450, $cod->amountDue());

        $partial = $this->manual(['amount' => 450, 'payment_method' => 'cod']);
        $partial->forceFill(['amount_paid' => 100])->save();
        $this->assertEquals(350, $partial->fresh()->amountDue());
    }

    private function shopifyOrder(array $extra): Order
    {
        return Order::create($extra + [
            'shopify_order_id' => random_int(1000, 99999),
            'customer_name' => 'Sara',
            'phone' => '0612000000',
            'currency' => 'MAD',
            'confirmation_status' => 'confirmed',
            'shipping_address' => ['city' => 'Casablanca', 'address1' => '12 rue des Orangers'],
        ])->fresh();
    }

    private function manual(array $data): Order
    {
        return $this->order($data + [
            'customer_name' => 'Sara',
            'customer_phone' => '0612000000',
            'city' => 'Casablanca',
            'address' => '12 rue des Orangers',
        ])->fresh();
    }

    private function assign(Order $order): Mission
    {
        $user = User::factory()->create();
        $driver = Driver::create([
            'name' => 'Yassine', 'user_id' => $user->id, 'phone' => '0600000000', 'tariff_livraison' => 20, 'is_active' => true,
        ]);
        $order->forceFill(['confirmation_status' => 'confirmed'])->save();

        return app(OrderWorkflow::class)->assignDriver($order->fresh(), $driver->id)->missions()->first();
    }

    private function speedafCod(Order $order): float
    {
        $companyId = $order->company_id ?: Company::default()->id;
        $settings = SpeedafSetting::forCompany($companyId);
        $settings->forceFill(['enabled' => true, 'sender_city' => 'Casablanca'])->save();
        $payload = SpeedafShipmentService::for($settings->fresh())->buildPayload($order->fresh());

        return (float) $payload['codFee'];
    }

    private function siftPrice(Order $order): float
    {
        $settings = SiftSetting::forCompany((int) ($order->company_id ?: Company::default()->id));
        $preview = (new SiftShipmentService($settings, app(OrderWorkflow::class)))->preview($order);

        return (float) $preview['price'];
    }

    private function ozonPrice(Order $order): float
    {
        $settings = OzonSetting::forCompany((int) ($order->company_id ?: Company::default()->id));
        return (float) OzonShipmentService::for($settings)->preview($order)['price'];
    }
}
