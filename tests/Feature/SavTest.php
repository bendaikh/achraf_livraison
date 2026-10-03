<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Driver;
use App\Models\Mission;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SavRequest;
use App\Models\User;
use App\Services\ClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T7 — Retours / échanges with driver traceability, custody, tariff snapshot, order section. */
class SavTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function driver(string $name, float $retour = 7, float $echange = 10): Driver
    {
        $u = User::factory()->create(['role' => User::ROLE_LIVREUR]);

        return Driver::create(['name' => $name, 'user_id' => $u->id, 'phone' => '0600000000', 'tariff_livraison' => 20, 'tariff_retour' => $retour, 'tariff_echange' => $echange, 'is_active' => true]);
    }

    private function deliveredOrder(Driver $driver): Order
    {
        $o = $this->order(['customer_name' => 'Fatima', 'customer_phone' => '0612345678', 'amount' => 398, 'city' => 'Casablanca', 'address' => 'Rue 1']);
        $o->forceFill([
            'line_items' => [['id' => 11, 'title' => 'Chaussure', 'variant_title' => '42', 'sku' => 'CH-42', 'quantity' => 2, 'price' => 199]],
            'delivery_status' => 'delivered', 'confirmation_status' => 'confirmed', 'driver_id' => $driver->id, 'delivered_at' => now()->subDay(),
        ])->save();

        return $o;
    }

    public function test_exchange_full_flow_with_driver_custody_and_fee_snapshot(): void
    {
        $this->signInAdmin();
        $karim = $this->driver('Karim');
        $yassine = $this->driver('Yassine', 8, 12);
        $order = $this->deliveredOrder($karim);
        $p = Product::create(['company_id' => Company::default()->id, 'title' => 'Chaussure', 'status' => 'active', 'source' => 'manual']);
        $v43 = ProductVariant::create(['product_id' => $p->id, 'company_id' => $p->company_id, 'title' => '43', 'sku' => 'CH-43', 'price' => 199]);

        // Search delivered order by phone and prefill
        $this->getJson('/api/sav/orders?q=0612345678')->assertOk()->assertJsonPath('data.0.id', $order->id);
        $pre = $this->getJson("/api/sav/orders/{$order->id}/prefill")->assertOk();
        $pre->assertJsonPath('original_driver.name', 'Karim')->assertJsonPath('lines.0.key', 's11')->assertJsonPath('amount_paid', 398);

        // Validation: reason mandatory; exchange requires new product; qty ≤ ordered
        $this->postJson('/api/sav', ['order_id' => $order->id, 'type' => 'echange', 'pickup' => [['key' => 's11', 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors(['reason', 'deliver']);
        $this->postJson('/api/sav', ['order_id' => $order->id, 'type' => 'retour', 'reason' => 'Produit défectueux', 'pickup' => [['key' => 's11', 'quantity' => 3]]])->assertStatus(422);

        $res = $this->postJson('/api/sav', [
            'order_id' => $order->id, 'type' => 'echange', 'reason' => 'Mauvaise référence', 'comment' => 'Taille 43 demandée',
            'pickup' => [['key' => 's11', 'quantity' => 1]], 'deliver' => [['variant_id' => $v43->id, 'quantity' => 1]], 'driver_id' => $karim->id,
        ])->assertCreated();
        $id = $res->json('data.id');
        $res->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.driver_fee', 10)->assertJsonPath('data.deliver.0.state', 'with_driver');
        $sav = SavRequest::findOrFail($id);
        $this->assertSame('echange', Mission::find($sav->mission_id)->type);

        // Reassign to Yassine → snapshot re-taken from Yassine's tariff, history kept
        $this->postJson("/api/sav/$id/assign", ['driver_id' => $yassine->id])->assertOk()->assertJsonPath('data.driver_fee', 12);
        $karim->update(['tariff_echange' => 99]);

        // Driver space: Yassine sees it, Karim does not; driver cannot receive/close
        $this->actingAs($yassine->user);
        $this->getJson('/api/driver/sav')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.deliver.0.sku', 'CH-43');
        $this->postJson("/api/driver/sav/$id/action", ['action' => 'receive'])->assertStatus(422);
        $this->postJson("/api/driver/sav/$id/action", ['action' => 'picked_up'])->assertStatus(422); // retour only
        $this->postJson("/api/driver/sav/$id/action", ['action' => 'en_route'])->assertOk();
        $this->postJson("/api/driver/sav/$id/action", ['action' => 'exchanged'])->assertOk();
        $this->actingAs($karim->user);
        $this->getJson('/api/driver/sav')->assertJsonCount(0, 'data');
        $this->postJson("/api/driver/sav/$id/action", ['action' => 'returning'])->assertForbidden();

        // Back-office: custody shows old product with Yassine
        $this->signInAdmin();
        $custody = $this->getJson('/api/sav/custody')->assertOk();
        $custody->assertJsonPath('data.0.driver_name', 'Yassine')->assertJsonPath('data.0.to_return.0.sku', 'CH-42')->assertJsonCount(0, 'data.0.to_deliver');
        $this->assertSame(12.0, (float) Mission::find($sav->mission_id)->driver_price);
        $this->assertSame('terminee', Mission::find($sav->mission_id)->status);
        $this->assertEquals(12, app(ClosingService::class)->unclosedCompletedMissions($yassine->id)->sum('driver_price'));

        $this->postJson("/api/sav/$id/action", ['action' => 'close'])->assertStatus(422); // must receive first
        $this->postJson("/api/sav/$id/action", ['action' => 'receive'])->assertOk()->assertJsonPath('data.pickup.0.state', 'at_depot');
        $this->postJson("/api/sav/$id/action", ['action' => 'close'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->getJson('/api/sav/custody')->assertJsonCount(0, 'data');

        // Timeline + original order section + order history
        $show = $this->getJson("/api/sav/$id")->assertOk();
        $labels = collect($show->json('data.history'))->pluck('label')->all();
        foreach (['Demande créée', 'À attribuer', 'Attribuée à Karim', 'Réaffectée de Karim à Yassine', 'En route', 'Échange effectué', 'Réceptionné au dépôt', 'Clôturée'] as $l) {
            $this->assertContains($l, $labels);
        }
        $this->assertSame('Yassine (livreur)', collect($show->json('data.history'))->firstWhere('label', 'Échange effectué')['by']);
        $this->getJson("/api/orders/{$order->id}/sav")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('delivered', $order->fresh()->delivery_status); // original order untouched
    }

    public function test_return_postpone_problem_cancel_and_permissions(): void
    {
        $this->signInAdmin();
        $karim = $this->driver('Karim');
        $order = $this->deliveredOrder($karim);
        $notDelivered = $this->order(['customer_name' => 'X', 'amount' => 10]);
        $notDelivered->update(['line_items' => [['id' => 1, 'title' => 'A', 'quantity' => 1, 'price' => 10]]]);
        $this->postJson('/api/sav', ['order_id' => $notDelivered->id, 'type' => 'retour', 'reason' => 'Autre', 'comment' => 'x', 'pickup' => [['key' => 's1', 'quantity' => 1]]])->assertStatus(422);
        $this->postJson('/api/sav', ['order_id' => $order->id, 'type' => 'retour', 'reason' => 'Autre', 'pickup' => [['key' => 's11', 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors('comment');

        $id = $this->postJson('/api/sav', ['order_id' => $order->id, 'type' => 'retour', 'reason' => 'Produit défectueux', 'pickup' => [['key' => 's11', 'quantity' => 2]]])
            ->assertCreated()->assertJsonPath('data.status', 'to_assign')->json('data.id');
        $this->postJson("/api/sav/$id/assign", ['driver_id' => $karim->id])->assertOk()->assertJsonPath('data.driver_fee', 7);

        $this->actingAs($karim->user);
        $this->postJson("/api/driver/sav/$id/action", ['action' => 'postpone'])->assertStatus(422);
        $this->postJson("/api/driver/sav/$id/action", ['action' => 'postpone', 'postponed_until' => now()->addDay()->toDateTimeString()])->assertOk()->assertJsonPath('data.status', 'postponed');
        $this->postJson("/api/driver/sav/$id/action", ['action' => 'problem'])->assertStatus(422);
        $this->postJson("/api/driver/sav/$id/action", ['action' => 'problem', 'comment' => 'Adresse introuvable'])->assertOk();

        // Agent can create/follow but not receive; livreur has no back-office access
        $agent = User::factory()->create(['role' => User::ROLE_USER]);
        $this->actingAs($agent);
        $this->getJson('/api/sav')->assertOk();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_LIVREUR]));
        $this->getJson('/api/sav')->assertForbidden();

        $this->signInAdmin();
        $this->postJson("/api/sav/$id/action", ['action' => 'cancel', 'comment' => 'Client injoignable'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('annulee', Mission::find(SavRequest::find($id)->mission_id)->status);
        $this->getJson('/api/sav?status=open')->assertJsonCount(0, 'data');
        $this->getJson('/api/sav?status=all')->assertJsonCount(1, 'data');
    }
}
