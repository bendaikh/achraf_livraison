<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AutomationRunResource;
use App\Models\AutomationRun;
use App\Services\Automations\AutomationEngine;
use Illuminate\Http\Request;

class AutomationRunController extends Controller
{
    protected function companyId(Request $request): int
    {
        return $request->user()->resolveCompanyId();
    }

    public function index(Request $request)
    {
        $q = AutomationRun::query()
            ->forCompany($this->companyId($request))
            ->with('automation')
            ->latest('id');

        if ($request->filled('automation_id')) {
            $q->where('automation_id', (int) $request->query('automation_id'));
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($request->boolean('exclude_simulation')) {
            $q->where('simulation', false);
        }

        return AutomationRunResource::collection($q->paginate(50));
    }

    public function show(Request $request, AutomationRun $run)
    {
        abort_unless((int) $run->company_id === $this->companyId($request), 404);

        return new AutomationRunResource($run->load(['steps', 'automation']));
    }

    public function retry(Request $request, AutomationRun $run, AutomationEngine $engine)
    {
        abort_unless((int) $run->company_id === $this->companyId($request), 404);
        abort_unless($run->status === AutomationRun::STATUS_FAILED, 422, 'Seuls les runs en échec peuvent être réessayés.');

        $updated = $engine->retry($run);

        return new AutomationRunResource($updated->load('steps'));
    }
}
