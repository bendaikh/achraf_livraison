<?php

namespace App\Http\Controllers\Api\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\ClientAudienceSegment;
use App\Models\Company;
use App\Services\Campaigns\AudienceQueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AudienceSegmentController extends Controller
{
    public function __construct(protected AudienceQueryBuilder $audience) {}

    protected function companyId(Request $request): int
    {
        return (int) $request->user()->resolveCompanyId();
    }

    public function index(Request $request): JsonResponse
    {
        $items = ClientAudienceSegment::query()
            ->forCompany($this->companyId($request))
            ->orderBy('name')
            ->get()
            ->map(fn (ClientAudienceSegment $s) => $s->toApiArray());

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'definition' => ['required', 'array'],
        ]);

        $segment = ClientAudienceSegment::query()->create([
            'company_id' => $this->companyId($request),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'definition' => $data['definition'],
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $segment->toApiArray()], 201);
    }

    public function update(Request $request, int $segment): JsonResponse
    {
        $model = ClientAudienceSegment::query()->forCompany($this->companyId($request))->whereKey($segment)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'definition' => ['sometimes', 'array'],
        ]);
        $model->forceFill($data + ['updated_by' => $request->user()->id])->save();

        return response()->json(['data' => $model->fresh()->toApiArray()]);
    }

    public function destroy(Request $request, int $segment): JsonResponse
    {
        $model = ClientAudienceSegment::query()->forCompany($this->companyId($request))->whereKey($segment)->firstOrFail();
        $model->delete();

        return response()->json(['message' => 'Segment supprimé.']);
    }

    public function count(Request $request, int $segment): JsonResponse
    {
        $model = ClientAudienceSegment::query()->forCompany($this->companyId($request))->whereKey($segment)->firstOrFail();
        $company = Company::query()->findOrFail($this->companyId($request));
        $resolved = $this->audience->resolveWithExclusions($company, $model->definition);

        return response()->json([
            'data' => [
                'included_count' => $resolved['included_count'],
                'excluded_count' => $resolved['excluded_count'],
                'excluded_reasons' => $resolved['excluded'],
            ],
        ]);
    }
}
