<?php

namespace Tests\Feature;

use App\Models\ClientBlock;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T9 — Clients aggregated from orders by phone, blocking + alerts, segments, groups, notes. */
class ClientsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function seedClient(): void
    {
        // Same client, three phone formats → one client (212612345678).
        $this->order(['customer_name' => 'Fatima', 'customer_phone' => '06 12 34 56 78', 'amount' => 200, 'city' => 'Casablanca', 'address' => 'Rue 1']);
        $this->order(['customer_name' => 'Fatima Z.', 'customer_phone' => '+212612345678', 'amount' => 150, 'city' => 'Rabat', 'address' => 'Av 2']);
        $o = $this->order(['customer_name' => 'Fatima Zahra', 'customer_phone' => '0612-345-678', 'amount' => 100]);
        Order::query()->whereIn('id', Order::query()->where('phone_key', '212612345678')->orderBy('id')->limit(2)->pluck('id'))->update(['delivery_status' => 'delivered', 'confirmation_status' => 'confirmed']);
        $o->forceFill(['delivery_status' => 'returned', 'confirmation_status' => 'confirmed'])->save();
        $this->order(['customer_name' => 'Omar', 'customer_phone' => '0698765432', 'amount' => 90]);
    }

    public function test_clients_are_grouped_by_normalized_phone_with_real_stats(): void
    {
        $this->signInAdmin();
        $this->seedClient();

        $res = $this->getJson('/api/clients?search=Fatima')->assertOk();
        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', '212612345678')
            ->assertJsonPath('data.0.orders', 3)
            ->assertJsonPath('data.0.delivered', 2)
            ->assertJsonPath('data.0.returned', 1)
            ->assertJsonPath('data.0.name', 'Fatima Zahra');
        $this->assertEquals(33.3, $res->json('data.0.return_rate'));
        $this->assertEquals(450, $res->json('data.0.total'));

        $this->getJson('/api/clients/summary')->assertOk()->assertJsonPath('all', 2);
        // Segment: multi delivered → Fatima only; high return (≥2 orders & ≥30 %) → Fatima
        $this->getJson('/api/clients?tab=segment&segment=multi_delivered')->assertJsonCount(1, 'data');
        $this->getJson('/api/clients?tab=segment&segment=high_return')->assertJsonCount(1, 'data');
        $this->getJson('/api/clients?tab=segment&segment=good')->assertJsonCount(0, 'data');
        $this->getJson('/api/clients?tab=segment&segment=new')->assertJsonCount(2, 'data');

        $card = $this->getJson('/api/clients/212612345678')->assertOk();
        $card->assertJsonCount(3, 'orders');
        $this->assertCount(2, $card->json('addresses'));
    }

    public function test_block_unblock_is_historised_and_alerts_on_orders(): void
    {
        $admin = $this->signInAdmin();
        $this->seedClient();

        $this->postJson('/api/clients/212612345678/block', [])->assertStatus(422);
        $this->postJson('/api/clients/212612345678/block', ['reason' => 'Refus répétés à la livraison', 'comment' => '3 refus'])->assertOk();
        $this->postJson('/api/clients/212612345678/block', ['reason' => 'x'])->assertStatus(422);

        // New order from the same client (other format) → alert in Commandes and Confirmation.
        $new = $this->order(['customer_name' => 'Fatima', 'customer_phone' => '+212 6 12 34 56 78', 'amount' => 120]);
        $this->getJson('/api/orders/'.$new->id)->assertOk()->assertJsonPath('data.client_blocked.reason', 'Refus répétés à la livraison')
            ->assertJsonPath('data.client_blocked.blocked_by_name', $admin->name);
        $list = collect($this->getJson('/api/confirmation/orders?filter=to_confirm')->json('orders'));
        $this->assertSame('Refus répétés à la livraison', $list->firstWhere('id', $new->id)['client_blocked']['reason']);
        $this->getJson('/api/clients?tab=blocked')->assertJsonCount(1, 'data');

        $this->postJson('/api/clients/212612345678/unblock', ['reason' => 'Client a payé'])->assertOk();
        $this->getJson('/api/orders/'.$new->id)->assertJsonPath('data.client_blocked', null);
        $card = $this->getJson('/api/clients/212612345678')->assertOk();
        $card->assertJsonCount(1, 'blocks')->assertJsonPath('blocks.0.active', false)->assertJsonPath('blocks.0.unblock_reason', 'Client a payé');
        $this->assertSame(1, ClientBlock::count());
    }

    public function test_groups_notes_and_permissions(): void
    {
        $this->signInAdmin();
        $this->seedClient();

        $g = $this->postJson('/api/client-groups', ['name' => 'VIP', 'color' => '#059669'])->assertCreated()->json('data.id');
        $this->postJson("/api/client-groups/$g/members", ['keys' => ['212612345678', '212698765432', '999']])->assertOk()->assertJsonPath('message', '2 client(s) ajouté(s) au groupe « VIP ».');
        $this->getJson("/api/clients?tab=group&group_id=$g")->assertJsonCount(2, 'data');
        $this->deleteJson("/api/client-groups/$g/members/212698765432")->assertOk();
        $this->getJson('/api/clients/summary')->assertJsonPath('groups.0.count', 1);

        $this->postJson('/api/clients/212612345678/notes', ['body' => 'Préfère être appelée le soir'])->assertCreated();
        $this->getJson('/api/clients/212612345678')->assertJsonPath('notes.0.body', 'Préfère être appelée le soir')->assertJsonPath('groups.0.name', 'VIP');

        // Agent: can view & note, cannot block nor manage groups. Driver: no access.
        $agent = User::factory()->create(['role' => User::ROLE_USER]);
        $this->actingAs($agent);
        $this->getJson('/api/clients')->assertOk();
        $this->postJson('/api/clients/212612345678/block', ['reason' => 'x'])->assertForbidden();
        $this->postJson('/api/client-groups', ['name' => 'X'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_LIVREUR]));
        $this->getJson('/api/clients')->assertForbidden();
    }
}
