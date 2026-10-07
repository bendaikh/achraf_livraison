<?php

namespace App\Http\Controllers\Api\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\ClientVehicle;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientVehicleController extends Controller
{
    protected function companyId(Request $request): int
    {
        return (int) $request->user()->resolveCompanyId();
    }

    protected function assertVehiclesEnabled(Request $request): Company
    {
        $company = Company::query()->findOrFail($this->companyId($request));
        abort_unless($company->vehiclesEnabled(), 403, 'Les véhicules clients ne sont pas activés pour cette société.');

        return $company;
    }

    public function index(Request $request, string $key): JsonResponse
    {
        $this->assertVehiclesEnabled($request);
        $items = ClientVehicle::query()
            ->forCompany($this->companyId($request))
            ->where('phone_key', $key)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(fn (ClientVehicle $v) => $v->toApiArray());

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, string $key): JsonResponse
    {
        $this->assertVehiclesEnabled($request);
        $data = $this->validated($request);
        $companyId = $this->companyId($request);

        if (! empty($data['is_primary'])) {
            ClientVehicle::query()->forCompany($companyId)->where('phone_key', $key)->update(['is_primary' => false]);
        }

        $vehicle = ClientVehicle::query()->create([
            'company_id' => $companyId,
            'phone_key' => $key,
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'generation' => $data['generation'] ?? null,
            'phase' => $data['phase'] ?? null,
            'year' => $data['year'] ?? null,
            'body_type' => $data['body_type'] ?? null,
            'plate' => $data['plate'] ?? null,
            'is_primary' => (bool) ($data['is_primary'] ?? false),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $vehicle->toApiArray()], 201);
    }

    public function update(Request $request, string $key, int $vehicle): JsonResponse
    {
        $this->assertVehiclesEnabled($request);
        $companyId = $this->companyId($request);
        $model = ClientVehicle::query()->forCompany($companyId)->where('phone_key', $key)->whereKey($vehicle)->firstOrFail();
        $data = $this->validated($request, true);

        if (! empty($data['is_primary'])) {
            ClientVehicle::query()->forCompany($companyId)->where('phone_key', $key)->where('id', '!=', $model->id)->update(['is_primary' => false]);
        }

        $model->forceFill($data)->save();

        return response()->json(['data' => $model->fresh()->toApiArray()]);
    }

    public function destroy(Request $request, string $key, int $vehicle): JsonResponse
    {
        $this->assertVehiclesEnabled($request);
        $model = ClientVehicle::query()
            ->forCompany($this->companyId($request))
            ->where('phone_key', $key)
            ->whereKey($vehicle)
            ->firstOrFail();
        $model->delete();

        return response()->json(['message' => 'Véhicule supprimé.']);
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'nullable';

        return $request->validate([
            'brand' => [$req, 'string', 'max:80'],
            'model' => [$req, 'string', 'max:80'],
            'generation' => ['nullable', 'string', 'max:80'],
            'phase' => ['nullable', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'body_type' => ['nullable', 'string', 'max:80'],
            'plate' => ['nullable', 'string', 'max:40'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
    }
}
