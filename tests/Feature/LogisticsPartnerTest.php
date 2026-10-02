<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Driver;
use App\Models\LogisticsPartner;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsPartnerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signInAdmin();
    }

    protected function partner(array $attrs = []): LogisticsPartner
    {
        return LogisticsPartner::create($attrs + [
            'company_id' => $this->admin->resolveCompanyId(),
            'name' => 'Jumia',
            'type' => 'both',
            'phone' => '0600000000',
            'city' => 'Casablanca',
            'address' => 'Zone industrielle, Lot 12',
        ]);
    }

    public function test_admin_can_create_list_and_update_partner(): void
    {
        $res = $this->postJson('/api/logistics-partners', [
            'name' => 'Ozon', 'type' => 'depot', 'phone' => '0522000000', 'city' => 'Casablanca',
            'address' => 'Bd Zerktouni', 'contact_name' => 'Karim', 'note' => 'Dépôt avant 16h',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Ozon')
            ->assertJsonPath('data.type_label', 'Dépôt')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_favorite', false);

        $id = $res->json('data.id');
        $this->assertSame($this->admin->resolveCompanyId(), LogisticsPartner::find($id)->company_id);

        $this->putJson("/api/logistics-partners/{$id}", ['phone' => '0522111111', 'type' => 'both'])
            ->assertOk()->assertJsonPath('data.phone', '0522111111')->assertJsonPath('data.type', 'both');

        $this->getJson('/api/logistics-partners')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ozon');
    }

    public function test_validation_requires_name_and_valid_type_and_unique_name_per_company(): void
    {
        $this->postJson('/api/logistics-partners', ['type' => 'autre'])->assertStatus(422)->assertJsonValidationErrors(['name', 'type']);
        $this->partner(['name' => 'Speedaf']);
        $this->postJson('/api/logistics-partners', ['name' => 'Speedaf', 'type' => 'depot'])->assertStatus(422)->assertJsonValidationErrors(['name']);

        // Same name is allowed in another company.
        $other = Company::create(['name' => 'Autre', 'slug' => 'autre', 'is_active' => true]);
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN, 'company_id' => $other->id]);
        $this->actingAs($otherAdmin)->postJson('/api/logistics-partners', ['name' => 'Speedaf', 'type' => 'depot'])->assertCreated();
    }

    public function test_deactivate_activate_and_delete_rules(): void
    {
        $used = $this->partner(['name' => 'Jumia']);
        $unused = $this->partner(['name' => 'Speedaf']);
        $this->postJson('/api/missions', ['type' => 'depot_partenaire', 'logistics_partner_id' => $used->id])->assertCreated();

        $this->postJson("/api/logistics-partners/{$used->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/logistics-partners?status=inactive')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Jumia');
        $this->postJson("/api/logistics-partners/{$used->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);

        // Used in mission history → cannot be hard-deleted.
        $this->deleteJson("/api/logistics-partners/{$used->id}")->assertStatus(422);
        $this->assertDatabaseHas('logistics_partners', ['id' => $used->id]);

        // Never used → can be deleted.
        $this->deleteJson("/api/logistics-partners/{$unused->id}")->assertNoContent();
        $this->assertDatabaseMissing('logistics_partners', ['id' => $unused->id]);
    }

    public function test_partners_are_scoped_by_company(): void
    {
        $mine = $this->partner(['name' => 'Jumia']);
        $other = Company::create(['name' => 'Autre', 'slug' => 'autre', 'is_active' => true]);
        $theirs = LogisticsPartner::create(['company_id' => $other->id, 'name' => 'Ozon', 'type' => 'both']);

        $this->getJson('/api/logistics-partners')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->putJson("/api/logistics-partners/{$theirs->id}", ['name' => 'Hack'])->assertNotFound();
        $this->postJson("/api/logistics-partners/{$theirs->id}/deactivate")->assertNotFound();
        $this->postJson("/api/logistics-partners/{$theirs->id}/favorite")->assertNotFound();
        $this->deleteJson("/api/logistics-partners/{$theirs->id}")->assertNotFound();
        $this->assertSame('Ozon', $theirs->fresh()->name);

        // Cannot create a mission with another company's partner.
        $this->postJson('/api/missions', ['type' => 'depot_partenaire', 'logistics_partner_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors(['logistics_partner_id']);
    }

    public function test_drivers_cannot_access_partner_settings(): void
    {
        $livreur = User::factory()->create(['role' => User::ROLE_LIVREUR]);
        $this->actingAs($livreur)->getJson('/api/logistics-partners')->assertForbidden();
        $this->actingAs($livreur)->postJson('/api/logistics-partners', ['name' => 'X', 'type' => 'both'])->assertForbidden();
    }

    public function test_selector_filters_active_partners_by_category_with_favourites_first(): void
    {
        $this->partner(['name' => 'Amana', 'type' => 'depot']);
        $this->partner(['name' => 'Boutique Maarif', 'type' => 'ramassage']);
        $this->partner(['name' => 'Jumia', 'type' => 'both']);
        $this->partner(['name' => 'Speedaf', 'type' => 'depot', 'is_favorite' => true]);
        $this->partner(['name' => 'Ancien', 'type' => 'both', 'is_active' => false]);

        $depot = $this->getJson('/api/logistics-partners?category=depot&active=1')->assertOk()->json('data');
        $this->assertSame(['Speedaf', 'Amana', 'Jumia'], array_column($depot, 'name'));

        $ramassage = $this->getJson('/api/logistics-partners?category=ramassage&active=1')->json('data');
        $this->assertSame(['Boutique Maarif', 'Jumia'], array_column($ramassage, 'name'));

        // Favourite toggle moves a partner to the top.
        $jumia = LogisticsPartner::where('name', 'Jumia')->first();
        $this->postJson("/api/logistics-partners/{$jumia->id}/favorite")->assertOk()->assertJsonPath('data.is_favorite', true);
        $ramassage = $this->getJson('/api/logistics-partners?category=ramassage&active=1')->json('data');
        $this->assertSame(['Jumia', 'Boutique Maarif'], array_column($ramassage, 'name'));
    }

    public function test_mission_rejects_inactive_or_wrong_type_partner(): void
    {
        $inactive = $this->partner(['name' => 'Ancien', 'is_active' => false]);
        $pickupOnly = $this->partner(['name' => 'Boutique', 'type' => 'ramassage']);

        $this->postJson('/api/missions', ['type' => 'depot_partenaire', 'logistics_partner_id' => $inactive->id])
            ->assertStatus(422)->assertJsonValidationErrors(['logistics_partner_id']);
        $this->postJson('/api/missions', ['type' => 'depot_partenaire', 'logistics_partner_id' => $pickupOnly->id])
            ->assertStatus(422)->assertJsonValidationErrors(['logistics_partner_id']);
        $this->postJson('/api/missions', ['type' => 'ramassage', 'logistics_partner_id' => $pickupOnly->id])->assertCreated();
    }

    public function test_depot_mission_prefills_from_partner_and_assigns_driver(): void
    {
        $jumia = $this->partner();
        $driver = Driver::create(['name' => 'Yassine', 'tariff_depot_partenaire' => 6]);

        $res = $this->postJson('/api/missions', [
            'type' => 'depot_partenaire', 'logistics_partner_id' => $jumia->id, 'driver_id' => $driver->id,
            'items_description' => '12 colis', 'quantity' => 12,
        ])->assertCreated()
            ->assertJsonPath('data.contact_name', 'Jumia')
            ->assertJsonPath('data.phone', '0600000000')
            ->assertJsonPath('data.city', 'Casablanca')
            ->assertJsonPath('data.address', 'Zone industrielle, Lot 12')
            ->assertJsonPath('data.logistics_partner_id', $jumia->id)
            ->assertJsonPath('data.partner_snapshot.name', 'Jumia')
            ->assertJsonPath('data.driver.name', 'Yassine')
            ->assertJsonPath('data.driver_price', 6);

        $this->assertStringStartsWith('DEP-', $res->json('data.reference'));
    }

    public function test_override_at_mission_creation_does_not_alter_partner(): void
    {
        $jumia = $this->partner();

        $this->postJson('/api/missions', [
            'type' => 'ramassage', 'logistics_partner_id' => $jumia->id,
            'contact_name' => 'Jumia – entrepôt Ain Sebaa', 'phone' => '0611111111', 'address' => 'Ain Sebaa, Rue 5',
        ])->assertCreated()
            ->assertJsonPath('data.contact_name', 'Jumia – entrepôt Ain Sebaa')
            ->assertJsonPath('data.phone', '0611111111')
            ->assertJsonPath('data.address', 'Ain Sebaa, Rue 5')
            ->assertJsonPath('data.city', 'Casablanca') // not overridden → from partner
            ->assertJsonPath('data.partner_snapshot.phone', '0600000000');

        $jumia->refresh();
        $this->assertSame('Jumia', $jumia->name);
        $this->assertSame('0600000000', $jumia->phone);
        $this->assertSame('Zone industrielle, Lot 12', $jumia->address);
    }

    public function test_mission_snapshot_is_unaffected_by_later_partner_edits(): void
    {
        $jumia = $this->partner();
        $id = $this->postJson('/api/missions', ['type' => 'depot_partenaire', 'logistics_partner_id' => $jumia->id])
            ->assertCreated()->json('data.id');

        $this->putJson("/api/logistics-partners/{$jumia->id}", [
            'name' => 'Jumia Maroc', 'phone' => '0699999999', 'city' => 'Rabat', 'address' => 'Nouvelle adresse',
        ])->assertOk();
        $this->postJson("/api/logistics-partners/{$jumia->id}/deactivate")->assertOk();

        $mission = Mission::findOrFail($id);
        $this->assertSame('Jumia', $mission->contact_name);
        $this->assertSame('0600000000', $mission->phone);
        $this->assertSame('Casablanca', $mission->city);
        $this->assertSame('Zone industrielle, Lot 12', $mission->address);
        $this->assertSame('Jumia', $mission->partner_snapshot['name']);
        $this->assertSame('0600000000', $mission->partner_snapshot['phone']);

        $this->getJson("/api/missions/{$id}")->assertOk()->assertJsonPath('data.partner_snapshot.city', 'Casablanca');
    }

    public function test_mission_without_partner_still_works_and_requires_contact(): void
    {
        $this->postJson('/api/missions', ['type' => 'ramassage'])->assertStatus(422)->assertJsonValidationErrors(['contact_name']);
        $this->postJson('/api/missions', ['type' => 'ramassage', 'contact_name' => 'Client libre'])
            ->assertCreated()->assertJsonPath('data.logistics_partner_id', null)->assertJsonPath('data.partner_snapshot', null);
    }
}
