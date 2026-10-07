<?php

namespace App\Http\Controllers\Api\Campaigns;

use App\Http\Controllers\Controller;
use App\Services\Campaigns\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientConsentController extends Controller
{
    public function __construct(protected ConsentService $consent) {}

    protected function companyId(Request $request): int
    {
        return (int) $request->user()->resolveCompanyId();
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $row = $this->consent->getOrUnknown($this->companyId($request), $key);

        return response()->json(['data' => $row->toApiArray()]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:allowed,refused,unknown'],
            'source' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $row = $this->consent->set(
            $this->companyId($request),
            $key,
            $data['status'],
            $data['source'] ?? 'manual',
            $request->user()->id,
            $data['notes'] ?? null
        );

        return response()->json(['data' => $row->toApiArray(), 'message' => 'Consentement mis à jour.']);
    }
}
