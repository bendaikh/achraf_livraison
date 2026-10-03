<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T5 — Centre de confirmation (stats, queue navigation, calls, discounts, channel). */
class ConfirmationCentreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_stats_are_zero_without_data_and_hide_comparisons(): void
    {
        $this->signInAdmin();
        $this->getJson('/api/confirmation/stats')->assertOk()
            ->assertJsonPath('calls.today', 0)
            ->assertJsonPath('calls.yesterday', null)
            ->assertJsonPath('confirmed.today', 0)
            ->assertJsonPath('failed.today', 0)
            ->assertJsonPath('to_confirm', 0)
            ->assertJsonPath('mine.calls_today', 0);
    }

    public function test_calls_confirmations_and_cancellations_feed_the_counters(): void
    {
        $admin = $this->signInAdmin();
        $a = $this->order(['customer_name' => 'A', 'amount' => 100, 'product_name' => 'Sac']);
        $b = $this->order(['customer_name' => 'B', 'amount' => 100, 'product_name' => 'Sac']);
        $this->order(['customer_name' => 'C', 'amount' => 100, 'product_name' => 'Sac']);

        $this->postJson("/api/confirmation/orders/{$a->id}/calls", ['result' => 'answered', 'note' => 'OK client'])->assertCreated()
            ->assertJsonPath('call.result_label', 'Répondu')
            ->assertJsonPath('call.user_name', $admin->name);
        $this->postJson("/api/confirmation/orders/{$b->id}/calls", ['result' => 'wrong_number'])->assertCreated();
        $this->postJson("/api/confirmation/orders/{$a->id}/calls", ['result' => 'bad'])->assertUnprocessable();
        $this->postJson("/api/confirmation/orders/{$a->id}/confirm", ['channel' => 'whatsapp'])->assertOk()
            ->assertJsonPath('order.confirmation_channel', 'whatsapp');
        $this->postJson("/api/confirmation/orders/{$b->id}/cancel", ['reason' => 'Numéro incorrect'])->assertOk();

        $this->getJson('/api/confirmation/stats')->assertOk()
            ->assertJsonPath('calls.today', 2)
            ->assertJsonPath('confirmed.today', 1)
            ->assertJsonPath('failed.today', 1)
            ->assertJsonPath('to_confirm', 1)
            ->assertJsonPath('mine.confirmed_today', 1);

        $detail = $this->getJson("/api/confirmation/orders/{$a->id}")->assertOk();
        $detail->assertJsonPath('order.calls.0.result', 'answered')
            ->assertJsonPath('order.full.id', $a->id);
        $this->assertTrue(OrderStatusHistory::where('order_id', $a->id)->where('kind', 'appel')->exists());
    }

    public function test_queue_navigation_prev_next_and_after_processing(): void
    {
        $this->signInAdmin();
        $o1 = $this->order(['customer_name' => 'Un', 'amount' => 100]);
        $o2 = $this->order(['customer_name' => 'Deux', 'amount' => 100]);
        $o3 = $this->order(['customer_name' => 'Trois', 'amount' => 100]);
        // Newest first (same timestamp → id desc): o3, o2, o1
        $this->getJson("/api/confirmation/orders/{$o2->id}/siblings")->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('position', 2)
            ->assertJsonPath('prev_id', $o3->id)->assertJsonPath('next_id', $o1->id);
        $this->assertSame([$o3->id, $o2->id, $o1->id], collect($this->getJson('/api/confirmation/orders')->json('orders'))->pluck('id')->all());

        $this->postJson("/api/confirmation/orders/{$o3->id}/confirm")->assertOk();
        // Processed order left the queue → next = head of the queue.
        $this->getJson("/api/confirmation/orders/{$o3->id}/siblings")->assertJsonPath('in_queue', false)->assertJsonPath('next_id', $o2->id)->assertJsonPath('total', 2);
    }

    public function test_postponed_order_returns_to_queue_when_due(): void
    {
        $this->signInAdmin();
        $o = $this->order(['customer_name' => 'Rappel', 'amount' => 100]);
        $this->postJson("/api/confirmation/orders/{$o->id}/postpone", ['recall_at' => now()->addHour()->toIso8601String(), 'note' => 'Après 18h'])->assertOk();
        $this->getJson('/api/confirmation/stats')->assertJsonPath('to_confirm', 0);
        $this->travel(2)->hours();
        $this->getJson('/api/confirmation/stats')->assertJsonPath('to_confirm', 1)->assertJsonPath('postponed_due', 1);
    }

    public function test_discounts_change_total_with_history_and_permission(): void
    {
        $this->signInAdmin();
        $o = $this->order(['customer_name' => 'Remise', 'amount' => 300, 'product_name' => 'Sac']);

        $res = $this->postJson("/api/confirmation/orders/{$o->id}/discounts", ['type' => 'percent', 'value' => 10, 'reason' => 'Geste commercial'])->assertCreated();
        $this->assertEquals(30, $res->json('discount.amount'));
        $this->assertEquals(270, (float) $o->fresh()->total_price);
        $this->assertEquals(30, (float) $o->fresh()->discount_total);
        $this->postJson("/api/confirmation/orders/{$o->id}/discounts", ['type' => 'amount', 'value' => 500])->assertUnprocessable();

        $this->deleteJson("/api/confirmation/orders/{$o->id}/discounts/{$res->json('discount.id')}")->assertOk();
        $this->assertEquals(300, (float) $o->fresh()->total_price);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $o->id)->where('kind', 'remise')->count());
        $this->getJson("/api/confirmation/orders/{$o->id}")->assertJsonPath('order.discounts.0.removed_at', fn ($v) => $v !== null);

        // Agents without the permission cannot add discounts.
        Setting::setValue('role_permissions', ['orders.discount' => []]);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->postJson("/api/confirmation/orders/{$o->id}/discounts", ['type' => 'amount', 'value' => 10])->assertForbidden();
        // …but can still log calls.
        $this->postJson("/api/confirmation/orders/{$o->id}/calls", ['result' => 'no_answer'])->assertCreated();
    }

    public function test_drivers_cannot_use_confirmation_api(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_LIVREUR]));
        $this->getJson('/api/confirmation/orders')->assertForbidden();
        $this->getJson('/api/confirmation/stats')->assertForbidden();
    }
}
