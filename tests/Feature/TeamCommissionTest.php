<?php

namespace Tests\Feature;

use App\Models\AgentCommission;
use App\Models\DeliveryStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T6 — Équipe commerciale: services, users, access, agent assignment, commissions, performance. */
class TeamCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function createSalma(array $extra = []): User
    {
        $service = Service::where('name', 'Confirmation')->firstOrFail();
        $res = $this->postJson('/api/users', $extra + [
            'name' => 'Salma', 'email' => 'salma@lavfast.test', 'phone' => '0600000001', 'role' => 'user',
            'service_id' => $service->id, 'password' => 'secret-123', 'is_active' => true,
            'commission_mode' => 'per_delivered', 'commission_value' => 3,
        ])->assertCreated();

        return User::findOrFail($res->json('data.id'));
    }

    protected function driver(): Driver
    {
        $u = User::factory()->create(['role' => User::ROLE_LIVREUR]);

        return Driver::create(['name' => 'Yassine', 'user_id' => $u->id, 'phone' => '0600000000', 'tariff_livraison' => 20, 'is_active' => true]);
    }

    protected function setStatus(Order $order, string $code): void
    {
        $id = fn (string $c) => DeliveryStatus::where('code', $c)->value('id');
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $id('in_progress')])->assertOk();
        $this->postJson("/api/orders/{$order->id}/status", ['delivery_status_id' => $id($code), 'collected_amount' => $order->fresh()->total_price, 'reason' => 'Client absent', 'note' => 'test'])->assertOk();
    }

    protected function deliver(Order $order, Driver $driver): void
    {
        $this->postJson('/api/local-delivery/assign', ['order_ids' => [$order->id], 'driver_id' => $driver->id])->assertOk();
        $this->setStatus($order, 'delivered');
    }

    public function test_default_services_are_seeded_and_editable(): void
    {
        $this->signInAdmin();
        $names = collect($this->getJson('/api/services')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Confirmation', 'Commercial', 'SAV', 'Livraison', 'Responsable'], $names);
        $id = $this->postJson('/api/services', ['name' => 'Logistique'])->assertCreated()->json('data.id');
        $this->putJson("/api/services/{$id}", ['name' => 'Logistique', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson("/api/services/{$id}")->assertOk();
    }

    public function test_salma_scenario_three_dh_per_delivered_order(): void
    {
        $admin = $this->signInAdmin();
        $salma = $this->createSalma();
        $this->assertSame('Confirmation', $salma->service->name);
        $driver = $this->driver();

        $a = $this->order(['customer_name' => 'A', 'amount' => 200, 'product_name' => 'Sac']);
        $b = $this->order(['customer_name' => 'B', 'amount' => 150, 'product_name' => 'Sac']);
        $c = $this->order(['customer_name' => 'C', 'amount' => 100, 'product_name' => 'Sac']);
        $this->postJson('/api/orders/assign-agent', ['order_ids' => [$a->id, $b->id, $c->id], 'user_id' => $salma->id])->assertOk()->assertJsonPath('updated', 3);

        // Salma logs in: only her space, confirmation queue filtered on her orders.
        $this->actingAs($salma);
        $this->getJson('/api/confirmation/orders?agent=me')->assertOk()->assertJsonCount(3, 'orders')->assertJsonPath('orders.0.assigned_user_name', 'Salma');
        $this->getJson('/api/settings/confirmation-statuses')->assertForbidden();
        $this->getJson('/api/integrations/speedaf')->assertForbidden();
        $this->getJson('/api/closings')->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/dashboard')->assertForbidden();
        $this->postJson('/api/drivers', ['name' => 'X'])->assertForbidden();
        $this->getJson('/api/whatsapp/unread-count')->assertOk();
        foreach ([$a, $b, $c] as $o) {
            $this->postJson("/api/confirmation/orders/{$o->id}/confirm")->assertOk();
        }
        // Confirmation alone does not generate the "per delivered" commission.
        $this->assertSame(0, AgentCommission::count());

        $this->actingAs($admin);
        $this->deliver($a, $driver);
        $this->deliver($b, $driver);
        $this->postJson('/api/local-delivery/assign', ['order_ids' => [$c->id], 'driver_id' => $driver->id])->assertOk();
        $this->setStatus($c, 'returned');

        $lines = AgentCommission::where('user_id', $salma->id)->get();
        $this->assertCount(2, $lines);
        $this->assertEquals(6.0, $lines->sum('amount'));
        $this->assertTrue($lines->every(fn ($l) => $l->state === 'pending' && $l->rate == 3.0 && $l->trigger === 'delivered'));

        // Rate change is not retroactive.
        $this->putJson("/api/users/{$salma->id}", ['name' => 'Salma', 'email' => 'salma@lavfast.test', 'role' => 'user', 'commission_mode' => 'per_delivered', 'commission_value' => 5])->assertOk();
        $this->assertEquals(6.0, AgentCommission::where('user_id', $salma->id)->sum('amount'));

        // Performance.
        $row = collect($this->getJson('/api/team/performance?period=today')->assertOk()->json('rows'))->firstWhere('user_id', $salma->id);
        $this->assertSame(3, $row['assigned']);
        $this->assertSame(3, $row['confirmed']);
        $this->assertSame(2, $row['delivered']);
        $this->assertEquals(100.0, $row['confirmation_rate']);
        $this->assertEquals(66.7, $row['delivery_rate']);
        $this->assertEquals(6.0, $row['commissions_pending']);

        // Validate then pay.
        $ids = $lines->pluck('id')->all();
        $this->postJson('/api/team/commissions/transition', ['ids' => $ids, 'action' => 'pay'])->assertOk()->assertJsonPath('updated', 0);
        $this->postJson('/api/team/commissions/transition', ['ids' => $ids, 'action' => 'validate'])->assertOk()->assertJsonPath('updated', 2);
        $this->postJson('/api/team/commissions/transition', ['ids' => $ids, 'action' => 'pay'])->assertOk()->assertJsonPath('updated', 2);
        $paid = AgentCommission::find($ids[0]);
        $this->assertSame('paid', $paid->state);
        $this->assertSame($admin->id, $paid->validated_by);
        $this->assertNotNull($paid->paid_at);

        $show = $this->getJson("/api/users/{$salma->id}")->assertOk();
        $show->assertJsonPath('totals.paid.amount', 6)->assertJsonCount(2, 'commissions');

        // Agent sees only her own performance and cannot validate.
        $this->actingAs($salma->fresh());
        $this->assertCount(1, $this->getJson('/api/team/performance')->json('rows'));
        $this->postJson('/api/team/commissions/transition', ['ids' => $ids, 'action' => 'cancel'])->assertForbidden();
    }

    public function test_confirmation_trigger_and_return_cancellation_rules(): void
    {
        $this->signInAdmin();
        $agent = $this->createSalma(['email' => 'karima@lavfast.test', 'commission_mode' => 'percent', 'commission_value' => 10, 'commission_trigger' => 'shipped']);
        $driver = $this->driver();
        $o = $this->order(['customer_name' => 'A', 'amount' => 300, 'product_name' => 'Sac']);
        $this->actingAs($agent);
        $this->postJson("/api/confirmation/orders/{$o->id}/confirm")->assertOk();
        $this->signInAdmin();
        $this->postJson('/api/local-delivery/assign', ['order_ids' => [$o->id], 'driver_id' => $driver->id])->assertOk();
        $line = AgentCommission::where('order_id', $o->id)->firstOrFail();
        $this->assertEquals(30.0, $line->amount);
        $this->assertEquals(300.0, $line->base_amount);
        // Returned afterwards → pending line cancelled (no definitive commission).
        $this->setStatus($o, 'returned');
        $this->assertSame('cancelled', $line->fresh()->state);
    }

    public function test_monthly_fixed_and_inactive_user_cannot_login(): void
    {
        $this->signInAdmin();
        $u = $this->createSalma(['email' => 'fixe@lavfast.test', 'commission_mode' => 'monthly', 'commission_value' => 2500]);
        $this->postJson('/api/team/commissions/monthly', ['month' => '2026-09'])->assertOk()->assertJsonPath('created', 1);
        $this->postJson('/api/team/commissions/monthly', ['month' => '2026-09'])->assertOk()->assertJsonPath('created', 0);
        $this->assertDatabaseHas('agent_commissions', ['user_id' => $u->id, 'period' => '2026-09', 'amount' => 2500]);

        $this->putJson("/api/users/{$u->id}", ['name' => 'Salma', 'email' => 'fixe@lavfast.test', 'role' => 'user', 'is_active' => false])->assertOk();
        auth()->guard('web')->logout();
        $this->postJson('/login', ['email' => 'fixe@lavfast.test', 'password' => 'secret-123'])->assertUnprocessable();
    }
}
