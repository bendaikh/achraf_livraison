<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Paramètres → Équipe & rémunération → Services (CRUD). */
class ServiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Service::query()->withCount('users')->orderBy('position')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $service = Service::create($data + ['company_id' => $request->user()->resolveCompanyId(), 'position' => (int) Service::max('position') + 1]);

        return response()->json(['message' => 'Service créé.', 'data' => $service], 201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $service->update($this->validated($request));

        return response()->json(['message' => 'Service enregistré.', 'data' => $service]);
    }

    public function destroy(Service $service): JsonResponse
    {
        if ($service->users()->exists()) {
            return response()->json(['message' => 'Ce service a des utilisateurs : désactivez-le plutôt que de le supprimer.'], 422);
        }
        $service->delete();

        return response()->json(['message' => 'Service supprimé.']);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['name.required' => 'Le nom du service est obligatoire.']);
    }
}
