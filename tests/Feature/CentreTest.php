<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T3 — page « Centre » : card registry + real counters. */
class CentreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function card(array $cards, string $key): ?array
    {
        return collect($cards)->firstWhere('key', $key);
    }

    public function test_empty_database_gives_zero_counters_for_every_card(): void
    {
        $this->signInAdmin();
        $res = $this->getJson('/api/centre')->assertOk();
        $cards = $res->json('cards');

        $this->assertSame(array_keys(config('centre.cards')), array_column($cards, 'key'));
        foreach ($cards as $card) {
            $this->assertSame(0, $card['count'], "card {$card['key']} should be 0 on an empty database");
            $this->assertNotEmpty($card['to']);
        }
    }

    public function test_counters_follow_real_orders(): void
    {
        $this->signInAdmin();
        $pending = $this->order(['customer_name' => 'A', 'amount' => 100, 'product_name' => 'Sac']);
        $confirmed = $this->order(['customer_name' => 'B', 'amount' => 100, 'product_name' => 'Sac']);
        $this->postJson("/api/orders/{$confirmed->id}/confirmation", ['confirmation_status' => 'confirmed'])->assertOk();
        $assigned = $this->order(['customer_name' => 'C', 'amount' => 100, 'product_name' => 'Sac']);
        $this->postJson("/api/orders/{$assigned->id}/confirmation", ['confirmation_status' => 'confirmed'])->assertOk();
        $user = User::factory()->create(['role' => User::ROLE_LIVREUR]);
        $driver = Driver::create(['name' => 'Yassine', 'user_id' => $user->id, 'phone' => '0600000000', 'tariff_livraison' => 20, 'is_active' => true]);
        $this->postJson('/api/local-delivery/assign', ['order_ids' => [$assigned->id], 'driver_id' => $driver->id])->assertOk();

        $cards = $this->getJson('/api/centre')->assertOk()->json('cards');
        $this->assertSame(1, $this->card($cards, 'confirmation')['count']);
        $this->assertSame(1, $this->card($cards, 'traitement')['count']);
        $this->assertSame(1, $this->card($cards, 'livraison')['count']);
        $this->assertSame(0, $this->card($cards, 'retours')['count']);

        // Link of the "livraison" card lists exactly the counted orders.
        $ids = collect($this->getJson('/api/orders?driver_id=any&status_category=avant_livraison,en_livraison,report')->json('data'))->pluck('id')->all();
        $this->assertSame([$assigned->id], $ids);
        $this->assertNotNull($pending->id);
    }

    public function test_out_of_stock_card_and_filter(): void
    {
        $this->signInAdmin();
        $product = Product::create(['company_id' => Company::default()->id, 'title' => 'Sac cuir', 'status' => 'active', 'source' => 'manual']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'company_id' => $product->company_id, 'title' => 'Noir', 'sku' => 'SAC-N', 'price' => 199, 'inventory_quantity' => 0, 'inventory_tracked' => true, 'inventory_policy' => 'deny']);
        $order = $this->order(['customer_name' => 'A', 'amount' => 199, 'product_name' => 'Sac cuir']);
        $order->update(['line_items' => [['name' => 'Sac cuir', 'quantity' => 1, 'price' => 199, 'sku' => 'SAC-N', 'variant_id' => $variant->id, 'product_id' => $product->id]]]);
        $this->order(['customer_name' => 'B', 'amount' => 100, 'product_name' => 'Autre']);

        $cards = $this->getJson('/api/centre')->json('cards');
        $this->assertSame(1, $this->card($cards, 'rupture')['count']);
        $ids = collect($this->getJson('/api/orders?out_of_stock=1')->json('data'))->pluck('id')->all();
        $this->assertSame([$order->id], $ids);
    }

    public function test_hidden_cards_setting_and_auth(): void
    {
        $this->getJson('/api/centre')->assertUnauthorized();
        $this->signInAdmin();
        Setting::setValue('centre_hidden_cards', ['transactions', 'echanges']);
        $keys = array_column($this->getJson('/api/centre')->json('cards'), 'key');
        $this->assertNotContains('transactions', $keys);
        $this->assertNotContains('echanges', $keys);
        $this->assertContains('confirmation', $keys);
    }
}
