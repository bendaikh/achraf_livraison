<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Automations\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signInAdmin();
    }

    /** @return array{entry: string, steps: array} */
    protected function sampleDefinition(string $city = 'Casablanca'): array
    {
        return [
            'entry' => 'if_city',
            'steps' => [
                'if_city' => [
                    'type' => 'condition',
                    'logic' => 'and',
                    'rules' => [
                        ['field' => 'city', 'op' => 'eq', 'value' => $city],
                    ],
                    'then' => 'wait_0',
                    'else' => null,
                ],
                'wait_0' => [
                    'type' => 'wait',
                    'amount' => 0,
                    'unit' => 'minutes',
                    'recheck_conditions' => true,
                    'conditions' => [
                        'logic' => 'and',
                        'rules' => [
                            ['field' => 'city', 'op' => 'eq', 'value' => $city],
                        ],
                    ],
                    'next' => 'add_note',
                ],
                'add_note' => [
                    'type' => 'action',
                    'action' => 'internal.add_note',
                    'config' => [
                        'note' => 'Auto note {{order.city}}',
                        'field' => 'internal_note',
                    ],
                    'next' => null,
                ],
            ],
        ];
    }

    protected function makeAutomation(array $attrs = []): Automation
    {
        return Automation::create($attrs + [
            'company_id' => $this->admin->resolveCompanyId(),
            'name' => 'Test auto',
            'status' => Automation::STATUS_PAUSED, // paused by default so Order observer does not fire scenarios mid-test
            'trigger_type' => 'order.created',
            'trigger_config' => [],
            'definition' => $this->sampleDefinition(),
            'version' => 1,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    public function test_crud_and_stats_are_scoped_by_company(): void
    {
        $mine = $this->makeAutomation(['name' => 'Mine', 'status' => Automation::STATUS_ACTIVE]);
        $other = Company::create(['name' => 'Autre', 'slug' => 'autre-auto', 'is_active' => true]);
        $theirs = Automation::create([
            'company_id' => $other->id,
            'name' => 'Theirs',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => 'order.created',
            'definition' => $this->sampleDefinition(),
            'version' => 1,
        ]);

        $this->getJson('/api/automations')->assertOk()
            ->assertJsonPath('data.0.name', 'Mine')
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/automations/{$theirs->id}")->assertNotFound();
        $this->putJson("/api/automations/{$theirs->id}", ['name' => 'Hack'])->assertNotFound();

        $this->getJson('/api/automations/stats')->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.active', 1);

        $this->postJson('/api/automations', [
            'name' => 'Créée API',
            'trigger_type' => 'manual',
            'definition' => $this->sampleDefinition(),
        ])->assertCreated()->assertJsonPath('data.name', 'Créée API');

        $this->assertDatabaseHas('automations', [
            'name' => 'Créée API',
            'company_id' => $this->admin->resolveCompanyId(),
        ]);

        $this->assertSame('Mine', $mine->fresh()->name);
    }

    public function test_simulation_runs_end_to_end_and_adds_no_real_note_side_effect_flag(): void
    {
        $auto = $this->makeAutomation(); // paused — no observer side effects
        $order = Order::withoutEvents(fn () => $this->order([
            'customer_name' => 'Ali',
            'customer_phone' => '0611223344',
            'city' => 'Casablanca',
            'amount' => 199,
        ]));

        $res = $this->postJson("/api/automations/{$auto->id}/test", ['order_id' => $order->id])
            ->assertOk();

        $this->assertSame('success', $res->json('data.run.status'));
        $this->assertTrue($res->json('data.run.simulation'));
        $preview = $res->json('data.preview');
        $this->assertNotEmpty($preview);
        $types = collect($preview)->pluck('type')->all();
        $this->assertContains('condition', $types);
        $this->assertContains('wait', $types);
        $this->assertContains('action', $types);

        // Simulation must not mutate the order note
        $this->assertNull($order->fresh()->internal_note);
    }

    public function test_real_engine_run_is_idempotent_on_same_key(): void
    {
        $auto = $this->makeAutomation();
        $order = Order::withoutEvents(fn () => $this->order([
            'customer_name' => 'Sara',
            'customer_phone' => '0611000000',
            'city' => 'Casablanca',
            'amount' => 50,
        ]));

        /** @var AutomationEngine $engine */
        $engine = app(AutomationEngine::class);
        $key = 'evt:order.created:'.$order->id;

        $run1 = $engine->start($auto, $order, ['order_id' => $order->id], $key, simulate: false);
        $run2 = $engine->start($auto, $order, ['order_id' => $order->id], $key, simulate: false);

        $this->assertSame($run1->id, $run2->id);
        $this->assertSame(1, AutomationRun::query()
            ->where('automation_id', $auto->id)
            ->where('idempotency_key', $key)
            ->count());

        $this->assertStringContainsString('Casablanca', (string) $order->fresh()->internal_note);
    }

    public function test_wait_rechecks_conditions_and_cancels_when_subject_no_longer_matches(): void
    {
        $auto = $this->makeAutomation(['definition' => [
            'entry' => 'wait_recheck',
            'steps' => [
                'wait_recheck' => [
                    'type' => 'wait',
                    'amount' => 5,
                    'unit' => 'minutes',
                    'recheck_conditions' => true,
                    'conditions' => [
                        'logic' => 'and',
                        'rules' => [
                            ['field' => 'city', 'op' => 'eq', 'value' => 'Casablanca'],
                        ],
                    ],
                    'next' => 'add_note',
                ],
                'add_note' => [
                    'type' => 'action',
                    'action' => 'internal.add_note',
                    'config' => ['note' => 'should-not-run', 'field' => 'internal_note'],
                    'next' => null,
                ],
            ],
        ]]);

        $order = Order::withoutEvents(fn () => $this->order([
            'customer_name' => 'Omar',
            'customer_phone' => '0622000000',
            'city' => 'Rabat',
            'amount' => 10,
        ]));

        $engine = app(AutomationEngine::class);

        $run = AutomationRun::create([
            'company_id' => $auto->company_id,
            'automation_id' => $auto->id,
            'automation_version' => 1,
            'status' => AutomationRun::STATUS_WAITING,
            'simulation' => false,
            'trigger_type' => 'order.created',
            'trigger_payload' => ['order_id' => $order->id],
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'context' => app(\App\Services\Automations\VariableResolver::class)
                ->subjectContext($order, ['order_id' => $order->id]) + [
                    'company_id' => $auto->company_id,
                    '__waits' => ['wait_recheck' => ['scheduled_at' => now()->toIso8601String()]],
                ],
            'current_step_key' => 'wait_recheck',
            'started_at' => now(),
            'resume_at' => now(),
        ]);

        $updated = $engine->resumeWait($run, 'wait_recheck');
        $this->assertSame(AutomationRun::STATUS_CANCELLED, $updated->status);
        $this->assertNull($order->fresh()->internal_note);
    }

    public function test_wait_recheck_continues_when_conditions_still_match(): void
    {
        $auto = $this->makeAutomation(['definition' => [
            'entry' => 'wait_recheck',
            'steps' => [
                'wait_recheck' => [
                    'type' => 'wait',
                    'amount' => 5,
                    'unit' => 'minutes',
                    'recheck_conditions' => true,
                    'conditions' => [
                        'logic' => 'and',
                        'rules' => [
                            ['field' => 'city', 'op' => 'eq', 'value' => 'Casablanca'],
                        ],
                    ],
                    'next' => 'add_note',
                ],
                'add_note' => [
                    'type' => 'action',
                    'action' => 'internal.add_note',
                    'config' => ['note' => 'after-wait', 'field' => 'internal_note'],
                    'next' => null,
                ],
            ],
        ]]);

        $order = Order::withoutEvents(fn () => $this->order([
            'customer_name' => 'Nadia',
            'customer_phone' => '0633000000',
            'city' => 'Casablanca',
            'amount' => 10,
        ]));

        $engine = app(AutomationEngine::class);
        $resolver = app(\App\Services\Automations\VariableResolver::class);
        $run = AutomationRun::create([
            'company_id' => $auto->company_id,
            'automation_id' => $auto->id,
            'automation_version' => 1,
            'status' => AutomationRun::STATUS_WAITING,
            'simulation' => false,
            'trigger_type' => 'order.created',
            'trigger_payload' => [],
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'context' => $resolver->subjectContext($order) + [
                'company_id' => $auto->company_id,
                '__waits' => ['wait_recheck' => ['scheduled_at' => now()->toIso8601String()]],
            ],
            'current_step_key' => 'wait_recheck',
            'started_at' => now(),
            'resume_at' => now(),
        ]);

        $updated = $engine->resumeWait($run, 'wait_recheck');
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $updated->status);
        $this->assertStringContainsString('after-wait', (string) $order->fresh()->internal_note);
    }

    public function test_catalog_exposes_registered_triggers_and_actions(): void
    {
        $this->getJson('/api/automations/catalog')->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'triggers',
                    'condition_fields',
                    'actions',
                    'operators',
                    'wait_units',
                ],
            ]);

        $catalog = $this->getJson('/api/automations/catalog')->json('data');
        $this->assertNotEmpty($catalog['triggers']);
        $actions = collect($catalog['actions'])->pluck('key');
        $this->assertTrue($actions->contains('internal.add_note'));
        $this->assertTrue($actions->contains('whatsapp.send_message'));
        $this->assertTrue($actions->contains('speedaf.create_parcel'));
    }
}
