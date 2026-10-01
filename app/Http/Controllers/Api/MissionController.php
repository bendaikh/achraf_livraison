<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MissionResource;
use App\Models\Mission;
use App\Services\MissionService;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MissionController extends Controller
{
    public function __construct(protected MissionService $service) {}

    public function index(Request $request)
    {
        $q = Mission::query()->with(['driver', 'order']);

        if ($request->filled('type')) {
            $q->whereIn('type', explode(',', $request->query('type')));
        }
        if ($request->filled('status')) {
            $status = $request->query('status');
            $q->whereIn('status', $status === 'open' ? Catalog::openMissionStatuses() : explode(',', $status));
        }
        if ($request->filled('driver_id')) {
            $q->where('driver_id', $request->integer('driver_id'));
        }
        if ($request->filled('date_from')) {
            $q->whereDate('scheduled_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $q->whereDate('scheduled_date', '<=', $request->query('date_to'));
        }
        if ($search = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('reference', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%"));
        }

        $perPage = min(max($request->integer('per_page', 50), 5), 200);

        return MissionResource::collection($q->orderByDesc('scheduled_date')->orderByDesc('id')->paginate($perPage));
    }

    public function show(Mission $mission)
    {
        return new MissionResource($mission->load(['driver', 'order', 'histories.user']));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $mission = $this->service->create($data);

        return (new MissionResource($mission->fresh()->load(['driver', 'order'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Mission $mission)
    {
        $rules = $this->rules();
        unset($rules['type']);
        $rules['contact_name'] = ['sometimes', 'string', 'max:255'];
        $data = $request->validate($rules);

        if (array_key_exists('driver_id', $data)) {
            $this->service->assign($mission, $data['driver_id'] ? (int) $data['driver_id'] : null);
            unset($data['driver_id']);
        }
        $mission->fill($data)->save();

        return new MissionResource($mission->fresh()->load(['driver', 'order']));
    }

    public function changeStatus(Request $request, Mission $mission)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Catalog::MISSION_STATUSES))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->service->changeStatus($mission, $data['status'], $data['note'] ?? null);

        return new MissionResource($mission->fresh()->load(['driver', 'order', 'histories.user']));
    }

    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(Catalog::MISSION_TYPES))],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'items_description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'scheduled_date' => ['nullable', 'date'],
            'time_slot' => ['nullable', 'string', 'max:60'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'cash_direction' => ['nullable', Rule::in(['collect', 'remit'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
