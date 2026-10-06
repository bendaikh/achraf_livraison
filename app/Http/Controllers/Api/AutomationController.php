<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AutomationResource;
use App\Http\Resources\AutomationRunResource;
use App\Models\Automation;
use App\Models\AutomationQueueJob;
use App\Models\AutomationRun;
use App\Models\AutomationTemplate;
use App\Models\AutomationVersion;
use App\Models\Order;
use App\Services\Automations\AutomationEngine;
use App\Services\Automations\AutomationRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AutomationController extends Controller
{
    protected function companyId(Request $request): int
    {
        return $request->user()->resolveCompanyId();
    }

    protected function authorizeAutomation(Request $request, Automation $automation): void
    {
        abort_unless((int) $automation->company_id === $this->companyId($request), 404);
    }

    /** Catalog: triggers / conditions / actions for the builder. */
    public function catalog(AutomationRegistry $registry)
    {
        return response()->json(['data' => $registry->catalog()]);
    }

    /** Stats cards for the list page. */
    public function stats(Request $request)
    {
        $companyId = $this->companyId($request);
        $base = Automation::query()->forCompany($companyId)->notArchived();
        $since = now()->subDay();

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('status', Automation::STATUS_ACTIVE)->count(),
                'runs_24h' => AutomationRun::query()->forCompany($companyId)
                    ->where('created_at', '>=', $since)->where('simulation', false)->count(),
                'errors_24h' => AutomationRun::query()->forCompany($companyId)
                    ->where('created_at', '>=', $since)
                    ->where('status', AutomationRun::STATUS_FAILED)->count(),
                'waiting' => AutomationRun::query()->forCompany($companyId)
                    ->where('status', AutomationRun::STATUS_WAITING)->count()
                    + AutomationQueueJob::query()->where('company_id', $companyId)
                        ->where('status', AutomationQueueJob::STATUS_PENDING)->count(),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $q = Automation::query()->forCompany($this->companyId($request))->notArchived()->latest('id');

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($search = trim((string) $request->query('q'))) {
            $q->where('name', 'like', "%{$search}%");
        }

        return AutomationResource::collection($q->paginate(50));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $userId = $request->user()->id;

        $automation = DB::transaction(function () use ($data, $request, $userId) {
            $automation = Automation::create($data + [
                'company_id' => $this->companyId($request),
                'status' => $data['status'] ?? Automation::STATUS_DRAFT,
                'version' => 1,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            AutomationVersion::create([
                'company_id' => $automation->company_id,
                'automation_id' => $automation->id,
                'version' => 1,
                'definition' => $automation->definition,
                'trigger_config' => $automation->trigger_config,
                'trigger_type' => $automation->trigger_type,
                'name' => $automation->name,
                'changed_by' => $userId,
                'change_note' => 'Création',
            ]);

            return $automation;
        });

        return (new AutomationResource($automation))->response()->setStatusCode(201);
    }

    public function show(Request $request, Automation $automation)
    {
        $this->authorizeAutomation($request, $automation);

        return new AutomationResource($automation);
    }

    public function update(Request $request, Automation $automation)
    {
        $this->authorizeAutomation($request, $automation);
        $data = $this->validated($request, $automation);
        $userId = $request->user()->id;

        DB::transaction(function () use ($automation, $data, $userId) {
            $definitionChanged = array_key_exists('definition', $data)
                && json_encode($data['definition']) !== json_encode($automation->definition);
            $triggerChanged = (array_key_exists('trigger_type', $data) && $data['trigger_type'] !== $automation->trigger_type)
                || (array_key_exists('trigger_config', $data)
                    && json_encode($data['trigger_config']) !== json_encode($automation->trigger_config));

            if ($definitionChanged || $triggerChanged) {
                $newVersion = $automation->version + 1;
                $data['version'] = $newVersion;
                AutomationVersion::create([
                    'company_id' => $automation->company_id,
                    'automation_id' => $automation->id,
                    'version' => $newVersion,
                    'definition' => $data['definition'] ?? $automation->definition,
                    'trigger_config' => $data['trigger_config'] ?? $automation->trigger_config,
                    'trigger_type' => $data['trigger_type'] ?? $automation->trigger_type,
                    'name' => $data['name'] ?? $automation->name,
                    'changed_by' => $userId,
                    'change_note' => 'Mise à jour',
                ]);
            }

            $automation->update($data + ['updated_by' => $userId]);
        });

        return new AutomationResource($automation->fresh());
    }

    public function duplicate(Request $request, Automation $automation)
    {
        $this->authorizeAutomation($request, $automation);
        $userId = $request->user()->id;

        $copy = Automation::create([
            'company_id' => $automation->company_id,
            'name' => $automation->name.' (copie)',
            'status' => Automation::STATUS_DRAFT,
            'trigger_type' => $automation->trigger_type,
            'trigger_config' => $automation->trigger_config,
            'definition' => $automation->definition,
            'version' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        AutomationVersion::create([
            'company_id' => $copy->company_id,
            'automation_id' => $copy->id,
            'version' => 1,
            'definition' => $copy->definition,
            'trigger_config' => $copy->trigger_config,
            'trigger_type' => $copy->trigger_type,
            'name' => $copy->name,
            'changed_by' => $userId,
            'change_note' => 'Duplication depuis #'.$automation->id,
        ]);

        return (new AutomationResource($copy))->response()->setStatusCode(201);
    }

    public function activate(Request $request, Automation $automation)
    {
        $this->authorizeAutomation($request, $automation);
        $automation->update([
            'status' => Automation::STATUS_ACTIVE,
            'updated_by' => $request->user()->id,
            'archived_at' => null,
        ]);

        return new AutomationResource($automation);
    }

    public function pause(Request $request, Automation $automation)
    {
        $this->authorizeAutomation($request, $automation);
        $automation->update([
            'status' => Automation::STATUS_PAUSED,
            'updated_by' => $request->user()->id,
        ]);

        return new AutomationResource($automation);
    }

    public function archive(Request $request, Automation $automation)
    {
        $this->authorizeAutomation($request, $automation);
        $automation->update([
            'status' => Automation::STATUS_PAUSED,
            'archived_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        return new AutomationResource($automation);
    }

    /** Simulation test against a real order (no side effects). */
    public function test(Request $request, Automation $automation, AutomationEngine $engine)
    {
        $this->authorizeAutomation($request, $automation);
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
        ]);

        $order = Order::query()->findOrFail($data['order_id']);
        // Soft company check via dispatcher helper when shop is set; manual orders → default company.
        $result = $engine->simulate($automation, $order, [
            'order_id' => $order->id,
            'manual_test' => true,
        ]);

        return response()->json([
            'data' => [
                'run' => new AutomationRunResource($result['run']->load('steps')),
                'preview' => $result['preview'],
                'trigger' => [
                    'type' => $automation->trigger_type,
                    'config' => $automation->trigger_config,
                ],
                'order' => [
                    'id' => $order->id,
                    'name' => $order->name,
                    'city' => $order->shipping_address['city'] ?? null,
                    'confirmation_status' => $order->confirmation_status,
                    'delivery_status' => $order->delivery_status,
                ],
            ],
        ]);
    }

    /** Manual trigger (real run, not simulation). */
    public function runManual(Request $request, Automation $automation, AutomationEngine $engine)
    {
        $this->authorizeAutomation($request, $automation);
        abort_unless($automation->isActive() || $automation->trigger_type === 'manual', 422, 'Automatisation inactive.');

        $data = $request->validate([
            'order_id' => ['nullable', 'integer'],
            'simulate' => ['sometimes', 'boolean'],
        ]);

        $order = ! empty($data['order_id']) ? Order::query()->findOrFail($data['order_id']) : null;
        $simulate = (bool) ($data['simulate'] ?? false);
        $run = $engine->start(
            $automation,
            $order,
            ['manual' => true, 'user_id' => $request->user()->id],
            idempotencyKey: null,
            simulate: $simulate,
        );

        return new AutomationRunResource($run->load('steps'));
    }

    public function templates(Request $request)
    {
        $templates = AutomationTemplate::query()
            ->availableFor($this->companyId($request))
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $templates]);
    }

    public function installTemplate(Request $request)
    {
        $data = $request->validate([
            'slug' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        $template = AutomationTemplate::query()
            ->availableFor($this->companyId($request))
            ->where('slug', $data['slug'])
            ->firstOrFail();

        $automation = Automation::create([
            'company_id' => $this->companyId($request),
            'name' => $data['name'] ?? $template->name,
            'status' => Automation::STATUS_DRAFT,
            'trigger_type' => $template->trigger_type,
            'trigger_config' => $template->trigger_config,
            'definition' => $template->definition,
            'version' => 1,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return (new AutomationResource($automation))->response()->setStatusCode(201);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Automation $existing = null): array
    {
        return $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'status' => ['sometimes', Rule::in(array_keys(Automation::STATUSES))],
            'trigger_type' => [$existing ? 'sometimes' : 'required', 'string', 'max:64'],
            'trigger_config' => ['nullable', 'array'],
            'definition' => [$existing ? 'sometimes' : 'required', 'array'],
            'definition.entry' => ['nullable', 'string', 'max:64'],
            'definition.steps' => ['required_with:definition', 'array'],
        ]);
    }
}
