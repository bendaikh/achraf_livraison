<?php

namespace Tests\Feature;

use App\Models\DeliveryStatus;
use App\Models\OrderStatusHistory;
use App\Models\Setting;
use App\Models\StatusTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T12 — Kanban: columns from configured statuses, real counts, same filters, validated moves. */
class KanbanTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_columns_follow_active_statuses_with_filtered_counts(): void
    {
        $this->signInAdmin();
        $a = $this->order(['customer_name' => 'Amine', 'amount' => 100, 'city' => 'Rabat']);
        $this->order(['customer_name' => 'Badr', 'amount' => 200, 'city' => 'Casablanca']);
        $c = $this->order(['customer_name' => 'Chama', 'amount' => 300, 'city' => 'Rabat']);
        $c->forceFill(['delivery_status' => 'delivered'])->save();
        DeliveryStatus::where('code', 'exchanged')->update(['is_active' => false]);

        $res = $this->getJson('/api/orders/kanban')->assertOk();
        $codes = collect($res->json('columns'))->pluck('status.code');
        $this->assertNotContains('exchanged', $codes);
        $this->assertSame(DeliveryStatus::where('is_active', true)->orderBy('sort_order')->orderBy('id')->value('code'), $codes->first());
        $col = fn ($r, $code) => collect($r->json('columns'))->firstWhere('status.code', $code);
        $first = $codes->first();
        $this->assertSame(2, $col($res, $first)['count']); // orders without status sit in the first column
        $this->assertSame(1, $col($res, 'delivered')['count']);

        $f = $this->getJson('/api/orders/kanban?city=Rabat')->assertOk();
        $this->assertSame(1, $col($f, $first)['count']);
        $this->assertSame('Amine', $col($f, $first)['orders'][0]['customer_name']);
        $this->assertSame($a->id, $col($f, $first)['orders'][0]['id']);
    }

    public function test_bulk_status_enforces_required_fields_and_transitions(): void
    {
        $admin = $this->signInAdmin();
        $o1 = $this->order(['customer_name' => 'A', 'amount' => 100]);
        $o2 = $this->order(['customer_name' => 'B', 'amount' => 100]);
        $cancelled = DeliveryStatus::where('code', 'cancelled')->first();

        // Annulée requires a reason → refused for every order, nothing changes
        $this->postJson('/api/orders/bulk-status', ['order_ids' => [$o1->id, $o2->id], 'delivery_status_id' => $cancelled->id])->assertStatus(422)->assertJsonCount(2, 'failed');
        $this->assertNull($o1->fresh()->delivery_status);

        $this->postJson('/api/orders/bulk-status', ['order_ids' => [$o1->id, $o2->id], 'delivery_status_id' => $cancelled->id, 'reason' => 'Client a annulé'])->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame('cancelled', $o1->fresh()->delivery_status);
        $h = OrderStatusHistory::where('order_id', $o1->id)->latest('id')->first();
        $this->assertSame($admin->id, $h->user_id);
        $this->assertSame('Annulée', $h->status_name);

        // Configured transitions are enforced (no bypass via drag & drop / bulk)
        Setting::setValue('enforce_status_transitions', true);
        $assigned = DeliveryStatus::where('code', 'assigned')->first();
        $delivered = DeliveryStatus::where('code', 'delivered')->first();
        StatusTransition::create(['from_status_id' => $cancelled->id, 'to_status_id' => $assigned->id]);
        $this->postJson('/api/orders/bulk-status', ['order_ids' => [$o1->id], 'delivery_status_id' => $delivered->id, 'collected_amount' => 100])
            ->assertStatus(422)->assertJsonPath('failed.0.reference', $o1->reference());
        $this->postJson('/api/orders/bulk-status', ['order_ids' => [$o1->id], 'delivery_status_id' => $assigned->id])->assertOk();
    }
}
