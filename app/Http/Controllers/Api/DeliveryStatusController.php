<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryStatusResource;
use App\Models\DeliveryStatus;
use App\Models\StatusTransition;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DeliveryStatusController extends Controller
{
    /** All statuses (admin) — `?active=1` returns only active ones (same as /api/meta). */
    public function index(Request $request)
    {
        $q = DeliveryStatus::query()->ordered()->with('transitionsFrom')->withCount(['orders', 'histories']);
        if ($request->boolean('active')) {
            $q->active();
        }

        return DeliveryStatusResource::collection($q->get());
    }

    public function store(Request $request)
    {
        if (! $request->filled('code') && $request->filled('name')) {
            $request->merge(['code' => Str::slug($request->input('name'), '_')]);
        }
        $data = $request->validate($this->rules());
        $data['sort_order'] ??= ((int) DeliveryStatus::max('sort_order')) + 10;
        $status = DeliveryStatus::create($data);

        return (new DeliveryStatusResource($this->reload($status)))->response()->setStatusCode(201);
    }

    public function update(Request $request, DeliveryStatus $deliveryStatus)
    {
        $data = $request->validate($this->rules($deliveryStatus, true));
        // Orders reference statuses by code: a used status keeps its code forever.
        if (isset($data['code']) && $data['code'] !== $deliveryStatus->code && $deliveryStatus->isUsed()) {
            return response()->json([
                'message' => 'Ce statut est déjà utilisé : son code interne ne peut plus être modifié.',
                'errors' => ['code' => ['Ce statut est déjà utilisé : son code interne ne peut plus être modifié.']],
            ], 422);
        }
        $deliveryStatus->update($data);

        return new DeliveryStatusResource($this->reload($deliveryStatus));
    }

    /** Hard delete only for statuses never used; used statuses must be deactivated instead. */
    public function destroy(DeliveryStatus $deliveryStatus)
    {
        if ($deliveryStatus->isUsed()) {
            return response()->json([
                'message' => 'Ce statut est déjà utilisé par des commandes ou dans l’historique : désactivez-le au lieu de le supprimer.',
            ], 409);
        }
        $deliveryStatus->delete();

        return response()->noContent();
    }

    public function transitions()
    {
        return response()->json([
            'data' => StatusTransition::query()->get(['id', 'from_status_id', 'to_status_id']),
        ]);
    }

    /** Replace the allowed target statuses from this status. */
    public function updateTransitions(Request $request, DeliveryStatus $deliveryStatus)
    {
        $data = $request->validate([
            'to_status_ids' => ['present', 'array'],
            'to_status_ids.*' => ['integer', 'exists:delivery_statuses,id', Rule::notIn([$deliveryStatus->id])],
        ]);
        DB::transaction(function () use ($deliveryStatus, $data) {
            $deliveryStatus->transitionsFrom()->whereNotIn('to_status_id', $data['to_status_ids'])->delete();
            foreach (array_unique($data['to_status_ids']) as $to) {
                StatusTransition::firstOrCreate(['from_status_id' => $deliveryStatus->id, 'to_status_id' => $to]);
            }
        });

        return new DeliveryStatusResource($this->reload($deliveryStatus));
    }

    protected function reload(DeliveryStatus $status): DeliveryStatus
    {
        return $status->fresh()->load('transitionsFrom')->loadCount(['orders', 'histories']);
    }

    protected function rules(?DeliveryStatus $status = null, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:100'],
            'code' => [$req, 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('delivery_statuses', 'code')->ignore($status?->id)],
            'color' => [$req, 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
            'category' => [$req, Rule::in(array_keys(Catalog::STATUS_CATEGORIES))],
            'required_fields' => ['sometimes', 'nullable', 'array'],
            'required_fields.*' => [Rule::in(array_keys(Catalog::REQUIRED_FIELDS))],
            'creates_mission_type' => ['sometimes', 'nullable', Rule::in(Catalog::STATUS_MISSION_TYPES)],
        ];
    }
}
