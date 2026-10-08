<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Company;
use App\Models\ConfirmationStatus;
use App\Models\DeliveryStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopifyShop;
use App\Models\User;
use App\Services\Automations\AutomationEngine;
use App\Services\CentreService;
use App\Services\Clients\ClientService;
use App\Services\Confirmation\ConfirmationStatusProvisioner;
use App\Services\ConfirmationStatusService;
use App\Services\DashboardService;
use App\Services\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConfirmationStatusesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_migration_keeps_codes_and_copies_them_per_company(): void
    {
        $default = Company::default();
        $codes = ConfirmationStatus::query()->where('company_id', $default->id)->orderBy('sort_order')->pluck('code')->all();
        $this->assertSame(
            ['to_confirm', 'postponed', 'no_answer', 'confirmed', 'cancelled'],
            $codes,
        );
        $confirmed = ConfirmationStatus::findByCode('confirmed', $default->id);
        $this->assertTrue($confirmed->counts_as_confirmed);
        $this->assertSame('confirmee', $confirmed->category);
        $this->assertTrue(ConfirmationStatus::findByCode('cancelled', $default->id)->counts_as_failure);
        $this->assertSame('a_recontacter', ConfirmationStatus::findByCode('postponed', $default->id)->category);

        $other = Company::create(['name' => 'Beta', 'slug' => 'beta-co']);
        $copied = ConfirmationStatus::query()->where('company_id', $other->id)->orderBy('sort_order')->pluck('code')->all();
        $this->assertSame($codes, $copied);

        $order = $this->order(['customer_name' => 'Ancien', 'customer_phone' => '0610000001', 'amount' => 80]);
        $this->assertSame($default->id, $order->company_id);
        $this->assertSame('À confirmer', $order->confirmationStatusDefinition()?->name);
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'kind' => 'confirmation',
            'status_code' => 'to_confirm',
            'status_name' => 'À confirmer',
        ]);
        $this->assertSame('À confirmer', ConfirmationStatus::labelFor('to_confirm', $order->company_id));

        $indexes = collect(Schema::getIndexes('confirmation_statuses'));
        $this->assertTrue($indexes->contains(fn ($index) => ($index['columns'] ?? []) === ['company_id', 'code'] && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique')));
        $this->assertFalse($indexes->contains(fn ($index) => ($index['columns'] ?? []) === ['code'] && ! ($index['primary'] ?? false) && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique')));

        ConfirmationStatus::create([
            'company_id' => $other->id,
            'name' => 'Doublon autorisé',
            'code' => 'rupture_de_stock',
            'color' => '#f97316',
            'category' => 'probleme_stock',
            'is_active' => true,
        ]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        ConfirmationStatus::create([
            'company_id' => $other->id,
            'name' => 'Doublon refusé',
            'code' => 'rupture_de_stock',
            'color' => '#f97316',
            'category' => 'probleme_stock',
            'is_active' => true,
        ]);
    }

    public function test_custom_status_appears_for_its_company_only_and_used_status_cannot_be_deleted(): void
    {
        $admin = $this->signInAdmin();
        $created = $this->postJson('/api/settings/confirmation-statuses', [
            'name' => 'Rupture de stock',
            'color' => '#f97316',
            'icon' => 'package-x',
            'category' => 'probleme_stock',
            'requires_product' => true,
            'requires_comment' => true,
            'requires_recall_date' => false,
            'stays_in_queue' => false,
            'is_active' => true,
            'show_in_filters' => true,
        ])->assertCreated();
        $this->assertSame('rupture_de_stock', $created->json('status.code'));

        $order = $this->order([
            'customer_name' => 'Stock',
            'customer_phone' => '0610000002',
            'amount' => 100,
            'city' => 'Casa',
        ]);
        $order->forceFill([
            'line_items' => [[
                'id' => 9,
                'title' => 'Tapis coffre Seat Leon',
                'variant_id' => 55,
                'quantity' => 1,
                'price' => '100.00',
            ]],
        ])->save();

        $list = $this->getJson('/api/confirmation/orders?filter=rupture_de_stock')->assertOk();
        $this->assertTrue(collect($list->json('statuses'))->pluck('code')->contains('rupture_de_stock'));
        $this->assertArrayHasKey('rupture_de_stock', $list->json('counts'));

        $centre = $this->getJson("/api/confirmation/orders/{$order->id}")->assertOk();
        $this->assertTrue(collect($centre->json('statuses'))->pluck('code')->contains('rupture_de_stock'));

        $other = Company::create(['name' => 'Gamma', 'slug' => 'gamma']);
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'company_id' => $other->id]);
        $this->actingAs($user);
        $foreign = collect($this->getJson('/api/settings/confirmation-statuses')->json('statuses'))->pluck('code');
        $this->assertFalse($foreign->contains('rupture_de_stock'));
        $this->assertTrue($foreign->contains('to_confirm'));

        $this->actingAs($admin);
        $statusId = $created->json('status.id');
        $this->travelTo(Carbon::parse('2026-10-07 23:30:00', 'Africa/Casablanca'));
        $this->postJson("/api/confirmation/orders/{$order->id}/status", [
            'status_code' => 'rupture_de_stock',
            'comment' => 'Plus de stock',
            'product_line_key' => '9',
            'expected_restock_date' => '2026-10-12',
        ])->assertOk();

        $this->deleteJson("/api/settings/confirmation-statuses/{$statusId}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ce statut est déjà utilisé : vous pouvez seulement le désactiver.');

        $this->putJson("/api/settings/confirmation-statuses/{$statusId}", ['is_active' => false])->assertOk()
            ->assertJsonPath('status.is_active', false);
        $this->assertFalse(collect($this->getJson('/api/confirmation/orders')->json('statuses'))->pluck('code')->contains('rupture_de_stock'));
        $still = $this->getJson("/api/confirmation/orders/{$order->id}")->assertOk();
        $this->assertSame('rupture_de_stock', $still->json('order.confirmation_status'));
        $this->assertTrue($still->json('order.confirmation_inactive'));

        $unused = $this->postJson('/api/settings/confirmation-statuses', [
            'name' => 'Brouillon test',
            'color' => '#64748b',
            'category' => 'personnalise',
        ])->assertCreated();
        $this->deleteJson('/api/settings/confirmation-statuses/'.$unused->json('status.id'))->assertOk();
        $this->assertNull(ConfirmationStatus::query()->find($unused->json('status.id')));
    }

    public function test_required_fields_history_quick_actions_and_kpis(): void
    {
        $admin = $this->signInAdmin();
        $companyId = $admin->resolveCompanyId();
        $this->postJson('/api/settings/confirmation-statuses', [
            'name' => 'Rupture de stock',
            'color' => '#f97316',
            'icon' => 'package-x',
            'category' => 'probleme_stock',
            'requires_product' => true,
            'requires_comment' => true,
            'reason_options' => ['Rupture', 'Casse'],
            'requires_reason' => true,
            'show_in_filters' => true,
        ])->assertCreated();

        $order = $this->order(['customer_name' => 'Nora', 'customer_phone' => '0610000003', 'amount' => 120, 'city' => 'Rabat']);
        $order->forceFill([
            'line_items' => [['id' => 9, 'title' => 'Tapis coffre Seat Leon', 'variant_id' => 55, 'quantity' => 1, 'price' => '120.00']],
            'delivery_status' => 'in_progress',
        ])->save();

        $this->postJson("/api/confirmation/orders/{$order->id}/status", ['status_code' => 'rupture_de_stock'])
            ->assertStatus(422);
        $this->postJson("/api/confirmation/orders/{$order->id}/status", [
            'status_code' => 'rupture_de_stock',
            'comment' => 'Note',
            'reason' => 'Ailleurs',
            'product_line_key' => '9',
        ])->assertStatus(422)->assertJsonPath('errors.reason.0', 'Choisissez un motif dans la liste.');
        $this->postJson("/api/confirmation/orders/{$order->id}/postpone", [])->assertStatus(422);

        $this->travelTo(Carbon::parse('2026-10-07 23:30:00', 'Africa/Casablanca'));
        $this->postJson("/api/confirmation/orders/{$order->id}/status", [
            'status_code' => 'rupture_de_stock',
            'comment' => 'Plus de stock',
            'reason' => 'Rupture',
            'product_line_key' => '9',
            'expected_restock_date' => '2026-10-12',
        ])->assertOk();

        $this->assertSame('in_progress', $order->fresh()->delivery_status);
        $row = OrderStatusHistory::query()->where('order_id', $order->id)->where('kind', 'confirmation')->latest('id')->first();
        $this->assertSame('to_confirm', $row->data['from_status']);
        $this->assertSame('rupture_de_stock', $row->data['to_status']);
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame('Rupture', $row->data['reason']);
        $this->assertSame('Plus de stock', $row->data['comment']);
        $this->assertSame('Tapis coffre Seat Leon', $row->data['product']);
        $this->assertSame('2026-10-12', $row->data['expected_restock_date']);
        $line = $this->getJson("/api/confirmation/orders/{$order->id}")->json('order.history_lines.0.formatted');
        $this->assertStringContainsString('07/10/2026 23:30', $line);
        $this->assertStringContainsString($admin->name.' : À confirmer → Rupture de stock', $line);
        $this->assertStringContainsString('Produit : Tapis coffre Seat Leon', $line);
        $this->assertStringContainsString('Réapprovisionnement prévu : 12/10/2026', $line);

        $this->putJson('/api/settings/confirmation-statuses/'.ConfirmationStatus::findByCode('confirmed', $companyId)->id, [
            'name' => 'Validée au téléphone',
        ])->assertOk();
        $second = $this->order(['customer_name' => 'Oui', 'customer_phone' => '0610000004', 'amount' => 50]);
        $this->postJson("/api/confirmation/orders/{$second->id}/confirm")->assertOk()
            ->assertJsonPath('order.confirmation_status', 'confirmed')
            ->assertJsonPath('order.confirmation_status_label', 'Validée au téléphone');
        $this->assertTrue($second->fresh()->isConfirmed());

        $this->postJson('/api/settings/confirmation-statuses', [
            'name' => 'Confirmée par WhatsApp',
            'color' => '#059669',
            'category' => 'personnalise',
            'counts_as_confirmed' => true,
            'show_in_filters' => false,
        ])->assertCreated();
        $third = $this->order(['customer_name' => 'Wa', 'customer_phone' => '0610000005', 'amount' => 70]);
        Automation::create([
            'company_id' => $companyId,
            'name' => 'Sur confirmation',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => 'order.confirmed',
            'trigger_config' => [],
            'definition' => [
                'entry' => 'note',
                'steps' => [
                    'note' => ['type' => 'action', 'action' => 'internal.add_note', 'config' => ['note' => 'confirmé', 'field' => 'internal_note'], 'next' => null],
                ],
            ],
            'version' => 1,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        Automation::create([
            'company_id' => $companyId,
            'name' => 'Changement',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => 'order.confirmation_changed',
            'trigger_config' => [],
            'definition' => [
                'entry' => 'note',
                'steps' => [
                    'note' => ['type' => 'action', 'action' => 'internal.add_note', 'config' => ['note' => 'changé', 'field' => 'note'], 'next' => null],
                ],
            ],
            'version' => 1,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $this->postJson("/api/confirmation/orders/{$third->id}/status", ['status_code' => 'confirmee_par_whatsapp'])->assertOk();
        $this->assertTrue($third->fresh()->isConfirmed());
        $stats = $this->getJson('/api/confirmation/stats')->assertOk();
        $this->assertGreaterThanOrEqual(2, $stats->json('confirmed.today'));
        $whatsapp = collect($stats->json('by_status'))->firstWhere('code', 'confirmee_par_whatsapp');
        $this->assertSame(1, $whatsapp['count']);
        $this->assertSame(1, $stats->json('other_statuses'));
        $run = AutomationRun::query()->where('trigger_type', 'order.confirmation_changed')->latest('id')->first();
        $this->assertSame('to_confirm', $run->trigger_payload['from_status']);
        $this->assertSame('confirmee_par_whatsapp', $run->trigger_payload['to_status']);
        $this->assertSame('personnalise', $run->trigger_payload['to_category']);
        $this->assertTrue(AutomationRun::query()->where('trigger_type', 'order.confirmed')->exists());

        $this->assertSame('in_progress', $order->fresh()->delivery_status);
        app(OrderWorkflow::class)->changeStatus($order->fresh(), DeliveryStatus::where('code', 'delivered')->first(), ['collected_amount' => 0]);
        $this->assertSame('rupture_de_stock', $order->fresh()->confirmation_status);

        ConfirmationStatus::flushCache($companyId);
        ConfirmationStatus::cachedAll($companyId);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ConfirmationStatusService::class)->filterCounts(Order::query()->where('company_id', $companyId), $companyId);
        $grouped = collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'group by'));
        $this->assertCount(1, $grouped);
    }

    public function test_no_answer_attempt_recall_buckets_and_simulation(): void
    {
        $admin = $this->signInAdmin();
        $companyId = $admin->resolveCompanyId();
        Company::default()->forceFill(['timezone' => 'Africa/Casablanca'])->save();
        $now = Carbon::parse('2026-10-08 15:00:00', 'Africa/Casablanca');
        $this->travelTo($now);

        $late = $this->order(['customer_name' => 'Retard', 'customer_phone' => '0610000011', 'amount' => 10]);
        $late->forceFill(['confirmation_status' => 'postponed', 'postponed_until' => $now->copy()->subHours(3)])->save();
        $today = $this->order(['customer_name' => 'Jour', 'customer_phone' => '0610000012', 'amount' => 10]);
        $today->forceFill(['confirmation_status' => 'postponed', 'postponed_until' => $now->copy()->addHours(2)])->save();
        $later = $this->order(['customer_name' => 'Futur', 'customer_phone' => '0610000013', 'amount' => 10]);
        $later->forceFill(['confirmation_status' => 'postponed', 'postponed_until' => $now->copy()->addDays(2)])->save();

        $recall = $this->getJson('/api/confirmation/stats')->json('recall');
        $this->assertSame(1, $recall['overdue']);
        $this->assertSame(1, $recall['today']);
        $this->assertSame(1, $recall['upcoming']);
        $overdueIds = collect($this->getJson('/api/confirmation/orders?filter=to_confirm&bucket=overdue')->json('orders'))->pluck('id');
        $this->assertTrue($overdueIds->contains($late->id));
        $this->assertFalse($overdueIds->contains($today->id));
        $this->assertSame(1, $this->getJson('/api/confirmation/stats')->json('to_confirm'));

        $silent = $this->order(['customer_name' => 'Silence', 'customer_phone' => '0610000014', 'amount' => 10]);
        $this->postJson("/api/confirmation/orders/{$silent->id}/no-answer")->assertOk();
        $this->postJson("/api/confirmation/orders/{$silent->id}/no-answer")->assertOk();
        $attempts = OrderStatusHistory::query()->where('order_id', $silent->id)->where('kind', 'confirmation')->orderBy('id')->get();
        $this->assertSame(1, $attempts[0]->data['attempt']);
        $this->assertSame(2, $attempts[1]->data['attempt']);
        $this->assertSame(2, $silent->calls()->count());

        $target = $this->order(['customer_name' => 'Sim', 'customer_phone' => '0610000015', 'amount' => 10]);
        $auto = Automation::create([
            'company_id' => $companyId,
            'name' => 'Set status',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => 'manual',
            'trigger_config' => [],
            'definition' => [
                'entry' => 'set',
                'steps' => [
                    'set' => [
                        'type' => 'action',
                        'action' => 'order.set_confirmation_status',
                        'config' => ['status_code' => 'no_answer'],
                        'next' => null,
                    ],
                ],
            ],
            'version' => 1,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $simulated = app(AutomationEngine::class)->simulate($auto, $target);
        $this->assertTrue($simulated['run']->simulation);
        $this->assertSame('success', $simulated['run']->status);
        $this->assertSame('to_confirm', $target->fresh()->confirmation_status);
        app(AutomationEngine::class)->start($auto, $target->fresh(), [], 'real-set');
        $this->assertSame('no_answer', $target->fresh()->confirmation_status);

        $needs = $this->postJson('/api/settings/confirmation-statuses', [
            'name' => 'Avec commentaire',
            'color' => '#0ea5e9',
            'category' => 'personnalise',
            'requires_comment' => true,
        ])->assertCreated();
        $dry = Automation::create([
            'company_id' => $companyId,
            'name' => 'Commentaire manquant',
            'status' => Automation::STATUS_PAUSED,
            'trigger_type' => 'manual',
            'trigger_config' => [],
            'definition' => [
                'entry' => 'set',
                'steps' => [
                    'set' => [
                        'type' => 'action',
                        'action' => 'order.set_confirmation_status',
                        'config' => ['status_code' => $needs->json('status.code')],
                        'next' => null,
                    ],
                ],
            ],
            'version' => 1,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $held = $this->order(['customer_name' => 'Hold', 'customer_phone' => '0610000016', 'amount' => 10]);
        $failed = app(AutomationEngine::class)->simulate($dry, $held);
        $this->assertSame('failed', $failed['run']->status);
        $this->assertStringContainsString('commentaire', mb_strtolower((string) $failed['run']->steps->last()?->error));
        $this->assertSame('to_confirm', $held->fresh()->confirmation_status);

        $catalog = $this->getJson('/api/automations/catalog')->assertOk();
        $field = collect($catalog->json('data.condition_fields'))->firstWhere('key', 'confirmation_status');
        $this->assertNotEmpty($field['options']);
        $action = collect($catalog->json('data.actions'))->firstWhere('key', 'order.set_confirmation_status');
        $this->assertSame('Changer le statut de confirmation', $action['label']);
        $this->assertNotEmpty(collect($action['config_schema'])->firstWhere('key', 'status_code')['options']);
    }

    public function test_legacy_custom_statuses_keep_flags_type_and_queue_and_are_copied(): void
    {
        $admin = $this->signInAdmin();
        $default = Company::default();
        $rows = [
            ['code' => 'ok_whatsapp', 'name' => 'OK WhatsApp', 'type' => 'success', 'queue_behavior' => null, 'is_terminal' => true],
            ['code' => 'annule_client', 'name' => 'Annulé client', 'type' => 'cancelled', 'queue_behavior' => null, 'is_terminal' => true],
            ['code' => 'rappel_vendredi', 'name' => 'Rappel vendredi', 'type' => 'waiting', 'queue_behavior' => 'future_only', 'is_terminal' => false],
            ['code' => 'en_file', 'name' => 'En file', 'type' => 'open', 'queue_behavior' => 'due_queue', 'is_terminal' => false],
        ];
        foreach ($rows as $i => $row) {
            DB::table('confirmation_statuses')->insert($row + [
                'color' => '#64748b',
                'sort_order' => 80 + $i,
                'is_active' => true,
                'is_default' => false,
                'show_in_filters' => false,
                'company_id' => null,
                'category' => null,
                'is_system' => false,
                'stays_in_queue' => false,
                'counts_as_confirmed' => false,
                'counts_as_failure' => false,
                'requires_recall_date' => false,
                'requires_time' => false,
                'requires_reason' => false,
                'requires_comment' => false,
                'requires_product' => false,
                'is_final' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $ok = $this->order(['customer_name' => 'Ok', 'customer_phone' => '0620000001', 'amount' => 20]);
        $ok->forceFill(['confirmation_status' => 'ok_whatsapp'])->save();
        $lost = $this->order(['customer_name' => 'Lost', 'customer_phone' => '0620000002', 'amount' => 20]);
        $lost->forceFill(['confirmation_status' => 'annule_client'])->save();
        $recall = $this->order(['customer_name' => 'Recall', 'customer_phone' => '0620000003', 'amount' => 20]);
        $recall->forceFill(['confirmation_status' => 'rappel_vendredi'])->save();
        $queue = $this->order(['customer_name' => 'Queue', 'customer_phone' => '0620000004', 'amount' => 20]);
        $queue->forceFill(['confirmation_status' => 'en_file'])->save();

        $migration = $this->phase4Migration();
        $backfill = new \ReflectionMethod($migration, 'backfillExisting');
        $backfill->setAccessible(true);
        $backfill->invoke($migration, $default->id);
        ConfirmationStatus::flushCache();

        $this->assertTrue($ok->fresh()->isConfirmed());
        $this->assertTrue(Order::confirmed()->whereKey($ok->id)->exists());
        $this->assertFalse($lost->fresh()->isConfirmed());
        $this->assertFalse(Order::confirmed()->whereKey($lost->id)->exists());
        $this->assertTrue(ConfirmationStatus::findByCode('annule_client', $default->id)->counts_as_failure);

        $expected = [
            'ok_whatsapp' => ['success', null, 'confirmee', true, false, false, false],
            'annule_client' => ['cancelled', null, 'annulee_echec', false, true, false, false],
            'rappel_vendredi' => ['waiting', 'future_only', 'a_recontacter', false, false, true, false],
            'en_file' => ['open', 'due_queue', 'en_attente', false, false, false, true],
        ];
        foreach ($expected as $code => [$type, $queueBehavior, $category, $confirmed, $failure, $recallDate, $stays]) {
            $status = ConfirmationStatus::query()->where('company_id', $default->id)->where('code', $code)->first();
            $this->assertNotNull($status, $code);
            $this->assertSame($type, $status->type);
            $this->assertSame($queueBehavior, $status->queue_behavior);
            $this->assertSame($category, $status->category);
            $this->assertSame($confirmed, $status->counts_as_confirmed);
            $this->assertSame($failure, $status->counts_as_failure);
            $this->assertSame($recallDate, $status->requires_recall_date);
            $this->assertSame($stays, $status->stays_in_queue);
            $this->assertFalse($status->is_system);
            $status->filter_label = 'revu';
            $status->save();
            $fresh = $status->fresh();
            $this->assertSame($type, $fresh->type);
            $this->assertSame($queueBehavior, $fresh->queue_behavior);
        }

        $other = Company::create(['name' => 'Legacy Co', 'slug' => 'legacy-co']);
        $copy = new \ReflectionMethod($migration, 'copyToOtherCompanies');
        $copy->setAccessible(true);
        $copy->invoke($migration, $default->id);
        ConfirmationStatus::flushCache($other->id);
        foreach ($expected as $code => [$type, $queueBehavior, $category, $confirmed, $failure, $recallDate, $stays]) {
            $status = ConfirmationStatus::query()->where('company_id', $other->id)->where('code', $code)->first();
            $this->assertNotNull($status, $code);
            $this->assertSame($type, $status->type);
            $this->assertSame($queueBehavior, $status->queue_behavior);
            $this->assertSame($category, $status->category);
            $this->assertSame($confirmed, $status->counts_as_confirmed);
            $this->assertSame($failure, $status->counts_as_failure);
            $this->assertSame($recallDate, $status->requires_recall_date);
            $this->assertSame($stays, $status->stays_in_queue);
        }
        $this->assertSame($admin->resolveCompanyId(), $default->id);
    }

    public function test_reprovision_does_not_overwrite_system_status_edits(): void
    {
        $admin = $this->signInAdmin();
        $status = ConfirmationStatus::findByCode('to_confirm', $admin->resolveCompanyId());
        $status->name = 'Nouvelle';
        $status->save();

        app(ConfirmationStatusProvisioner::class)->provision($admin->resolveCompanyId());

        $this->assertSame('Nouvelle', ConfirmationStatus::findByCode('to_confirm', $admin->resolveCompanyId())->name);
    }

    public function test_status_used_only_by_a_deleted_draft_cannot_be_deleted(): void
    {
        $this->signInAdmin();
        $created = $this->postJson('/api/settings/confirmation-statuses', [
            'name' => 'Brouillon seul',
            'color' => '#64748b',
            'category' => 'personnalise',
        ])->assertCreated();
        $order = $this->order(['customer_name' => 'Draft', 'customer_phone' => '0620000010', 'amount' => 15]);
        $order->forceFill([
            'confirmation_status' => $created->json('status.code'),
            'deleted_at' => now(),
            'flow_state' => 'draft',
        ])->save();
        $this->assertSame(0, Order::query()->where('confirmation_status', $created->json('status.code'))->count());

        $this->deleteJson('/api/settings/confirmation-statuses/'.$created->json('status.id'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ce statut est déjà utilisé : vous pouvez seulement le désactiver.');
    }

    public function test_confirmation_lookups_stay_inside_the_current_company(): void
    {
        $admin = $this->signInAdmin();
        $other = Company::create(['name' => 'Autre société', 'slug' => 'autre-societe']);
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN, 'company_id' => $other->id]);
        foreach ([[$admin->resolveCompanyId(), true, false], [$other->id, false, true]] as [$companyId, $confirmed, $failure]) {
            ConfirmationStatus::query()->create([
                'company_id' => $companyId,
                'name' => 'Special',
                'code' => 'special',
                'color' => '#111111',
                'category' => $confirmed ? 'confirmee' : 'annulee_echec',
                'counts_as_confirmed' => $confirmed,
                'counts_as_failure' => $failure,
                'is_active' => true,
            ]);
        }
        $order = $this->order(['customer_name' => 'Scope', 'customer_phone' => '0620000020', 'amount' => 30]);
        $order->forceFill(['confirmation_status' => 'special'])->save();

        $this->actingAs($admin);
        $dashboard = app(DashboardService::class);
        $conf = new \ReflectionMethod($dashboard, 'conf');
        $conf->setAccessible(true);
        $confirmedCodes = $conf->invoke($dashboard, 'confirmed');
        $failedCodes = $conf->invoke($dashboard, 'cancelled');
        $this->assertContains('special', $confirmedCodes);
        $this->assertNotContains('special', $failedCodes);
        $client = app(ClientService::class)->find($order->fresh()->phone_key);
        $this->assertSame(1, (int) $client->confirmed);
        $this->assertSame(0, (int) $client->cancelled);

        $this->actingAs($otherAdmin);
        $dashboard = app(DashboardService::class);
        $conf = new \ReflectionMethod($dashboard, 'conf');
        $conf->setAccessible(true);
        $confirmedCodes = $conf->invoke($dashboard, 'confirmed');
        $failedCodes = $conf->invoke($dashboard, 'cancelled');
        $this->assertNotContains('special', $confirmedCodes);
        $this->assertContains('special', $failedCodes);
        $client = app(ClientService::class)->find($order->fresh()->phone_key);
        $this->assertSame(0, (int) $client->confirmed);
        $this->assertSame(1, (int) $client->cancelled);

        $toConfirm = ConfirmationStatus::findByCode('to_confirm', $other->id);
        $toConfirm->is_default = false;
        $toConfirm->save();
        ConfirmationStatus::query()->create([
            'company_id' => $other->id,
            'name' => 'File B',
            'code' => 'file_b',
            'color' => '#222222',
            'category' => 'en_attente',
            'is_default' => true,
            'stays_in_queue' => true,
            'is_active' => true,
        ]);
        $mine = $this->order(['customer_name' => 'File', 'customer_phone' => '0620000021', 'amount' => 10]);
        $mine->forceFill(['confirmation_status' => 'file_b', 'company_id' => $other->id])->save();
        $this->assertSame(1, app(CentreService::class)->toConfirm());
    }

    public function test_confirmation_endpoints_refuse_another_companys_order(): void
    {
        $this->signInAdmin();
        $other = Company::create(['name' => 'Hors société', 'slug' => 'hors-societe']);
        $order = $this->order(['customer_name' => 'Etranger', 'customer_phone' => '0620000030', 'amount' => 40]);
        $order->forceFill(['company_id' => $other->id, 'confirmation_status' => 'to_confirm'])->save();
        $discount = OrderDiscount::create([
            'order_id' => $order->id,
            'type' => 'amount',
            'value' => 5,
            'amount' => 5,
        ]);
        $id = $order->id;

        $this->getJson("/api/confirmation/orders/{$id}")->assertNotFound();
        $this->postJson("/api/confirmation/orders/{$id}/status", ['status_code' => 'confirmed'])->assertNotFound();
        $this->postJson("/api/confirmation/orders/{$id}/confirm")->assertNotFound();
        $this->postJson("/api/confirmation/orders/{$id}/no-answer")->assertNotFound();
        $this->postJson("/api/confirmation/orders/{$id}/postpone", ['recall_at' => now()->addDay()->toIso8601String()])->assertNotFound();
        $this->postJson("/api/confirmation/orders/{$id}/cancel", ['reason' => 'test'])->assertNotFound();
        $this->putJson("/api/confirmation/orders/{$id}/notes", ['confirmation_note' => 'secret', 'internal_note' => 'secret'])->assertNotFound();
        $this->putJson("/api/confirmation/orders/{$id}/internal-note", ['internal_note' => 'secret'])->assertNotFound();
        $this->getJson("/api/confirmation/orders/{$id}/siblings")->assertNotFound();
        $this->postJson("/api/confirmation/orders/{$id}/calls", ['result' => 'no_answer', 'channel' => 'phone'])->assertNotFound();
        $this->postJson("/api/confirmation/orders/{$id}/discounts", ['type' => 'amount', 'value' => 5])->assertNotFound();
        $this->deleteJson("/api/confirmation/orders/{$id}/discounts/{$discount->id}")->assertNotFound();

        $fresh = $order->fresh();
        $this->assertSame('to_confirm', $fresh->confirmation_status);
        $this->assertNull($fresh->confirmation_note);
        $this->assertNull($fresh->internal_note);
        $this->assertSame(0, $fresh->calls()->count());
        $this->assertNull($discount->fresh()->removed_at);
    }

    private function phase4Migration(): object
    {
        foreach (get_declared_classes() as $class) {
            $ref = new \ReflectionClass($class);
            if ($ref->getFileName() && str_contains($ref->getFileName(), '2026_10_08_190000_confirmation_statuses_per_company.php')) {
                return $ref->newInstance();
            }
        }

        $loaded = require database_path('migrations/2026_10_08_190000_confirmation_statuses_per_company.php');
        if (is_object($loaded)) {
            return $loaded;
        }

        throw new \RuntimeException('Migration de confirmation introuvable.');
    }
}
