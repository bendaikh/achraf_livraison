<?php

namespace App\Http\Controllers;

use App\Models\ConfirmationStatus;
use App\Services\ConfirmationStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Paramètres → Statuts de confirmation.
 */
class ConfirmationStatusController extends Controller
{
    public function __construct(
        private readonly ConfirmationStatusService $statuses,
    ) {}

    /** Active statuses for Confirmation filters / badges. */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->resolveCompanyId();

        return response()->json([
            'statuses' => $this->statuses->activeAll($companyId),
            'tabs' => $this->statuses->activeFilters($companyId),
            'categories' => ConfirmationStatus::CATEGORIES,
        ]);
    }

    /** Full list including inactive — Paramètres. */
    public function settingsIndex(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);
        $companyId = $request->user()->resolveCompanyId();

        return response()->json([
            'statuses' => $this->statuses->allForSettings($companyId),
            'categories' => ConfirmationStatus::CATEGORIES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);
        $companyId = $request->user()->resolveCompanyId();
        $data = $this->validateStatus($request, $companyId);
        $maxOrder = (int) ConfirmationStatus::query()->forCompany($companyId)->max('sort_order');
        $code = $data['code'] ?? $this->codeFromName($data['name'], $companyId);

        $status = ConfirmationStatus::query()->create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'code' => $code,
            'color' => $data['color'],
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? ($maxOrder + 10),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'category' => $data['category'] ?? ConfirmationStatus::CATEGORY_CUSTOM,
            'filter_label' => $data['filter_label'] ?? null,
            'show_in_filters' => (bool) ($data['show_in_filters'] ?? false),
            'is_default' => false,
            'is_system' => false,
            'is_final' => (bool) ($data['is_final'] ?? $data['is_terminal'] ?? false),
            'stays_in_queue' => (bool) ($data['stays_in_queue'] ?? false),
            'counts_as_confirmed' => (bool) ($data['counts_as_confirmed'] ?? false),
            'counts_as_failure' => (bool) ($data['counts_as_failure'] ?? false),
            'requires_recall_date' => (bool) ($data['requires_recall_date'] ?? false),
            'requires_time' => (bool) ($data['requires_time'] ?? false),
            'requires_reason' => (bool) ($data['requires_reason'] ?? false),
            'requires_comment' => (bool) ($data['requires_comment'] ?? false),
            'requires_product' => (bool) ($data['requires_product'] ?? false),
            'reason_options' => $data['reason_options'] ?? null,
        ]);

        return response()->json([
            'status' => $status->fresh()->toApiArray(),
            'message' => 'Statut créé.',
        ], 201);
    }

    public function update(Request $request, ConfirmationStatus $confirmationStatus): JsonResponse
    {
        $this->authorizeSettings($request);
        $this->owns($request, $confirmationStatus);
        $data = $this->validateStatus($request, (int) $confirmationStatus->company_id, $confirmationStatus);

        if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
            if ($confirmationStatus->is_default) {
                return response()->json(['message' => 'Le statut par défaut ne peut pas être désactivé.'], 422);
            }
            if ($confirmationStatus->is_system) {
                $others = ConfirmationStatus::query()
                    ->forCompany((int) $confirmationStatus->company_id)
                    ->where('category', $confirmationStatus->category)
                    ->where('id', '!=', $confirmationStatus->id)
                    ->where('is_active', true)
                    ->exists();
                if (! $others) {
                    return response()->json([
                        'message' => 'Ce statut est le seul de sa catégorie : vous pouvez seulement le modifier.',
                    ], 422);
                }
            }
        }

        unset($data['code'], $data['is_system'], $data['is_default'], $data['company_id']);
        if ($confirmationStatus->is_system) {
            unset($data['category']);
        }
        if (array_key_exists('is_terminal', $data) && ! array_key_exists('is_final', $data)) {
            $data['is_final'] = $data['is_terminal'];
        }

        $confirmationStatus->fill($data)->save();

        return response()->json([
            'status' => $confirmationStatus->fresh()->toApiArray(),
            'message' => 'Statut mis à jour.',
        ]);
    }

    public function destroy(Request $request, ConfirmationStatus $confirmationStatus): JsonResponse
    {
        $this->authorizeSettings($request);
        $this->owns($request, $confirmationStatus);

        if ($confirmationStatus->is_system) {
            return response()->json([
                'message' => 'Ce statut système ne peut pas être supprimé. Vous pouvez seulement le désactiver.',
            ], 422);
        }

        if ($this->statuses->isUsed($confirmationStatus)) {
            return response()->json([
                'message' => 'Ce statut est déjà utilisé : vous pouvez seulement le désactiver.',
            ], 422);
        }

        $confirmationStatus->delete();

        return response()->json(['message' => 'Statut supprimé.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);
        $companyId = $request->user()->resolveCompanyId();

        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', Rule::exists('confirmation_statuses', 'id')->where('company_id', $companyId)],
        ]);

        DB::transaction(function () use ($data, $companyId) {
            foreach (array_values($data['order']) as $index => $id) {
                ConfirmationStatus::query()
                    ->forCompany($companyId)
                    ->where('id', $id)
                    ->update(['sort_order' => ($index + 1) * 10]);
            }
        });

        ConfirmationStatus::flushCache($companyId);

        return response()->json([
            'statuses' => $this->statuses->allForSettings($companyId),
            'message' => 'Ordre mis à jour.',
        ]);
    }

    private function authorizeSettings(Request $request): void
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'Accès réservé aux administrateurs.');
        }
    }

    private function owns(Request $request, ConfirmationStatus $status): void
    {
        abort_unless((int) $status->company_id === $request->user()->resolveCompanyId(), 404);
    }

    private function validateStatus(Request $request, int $companyId, ?ConfirmationStatus $existing = null): array
    {
        return $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:120'],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('confirmation_statuses', 'code')->where('company_id', $companyId)->ignore($existing?->id),
            ],
            'color' => [$existing ? 'sometimes' : 'required', 'string', 'max:32'],
            'icon' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'category' => ['nullable', 'string', Rule::in(array_keys(ConfirmationStatus::CATEGORIES))],
            'filter_label' => ['nullable', 'string', 'max:120'],
            'show_in_filters' => ['sometimes', 'boolean'],
            'is_terminal' => ['sometimes', 'boolean'],
            'is_final' => ['sometimes', 'boolean'],
            'stays_in_queue' => ['sometimes', 'boolean'],
            'counts_as_confirmed' => ['sometimes', 'boolean'],
            'counts_as_failure' => ['sometimes', 'boolean'],
            'requires_recall_date' => ['sometimes', 'boolean'],
            'requires_time' => ['sometimes', 'boolean'],
            'requires_reason' => ['sometimes', 'boolean'],
            'requires_comment' => ['sometimes', 'boolean'],
            'requires_product' => ['sometimes', 'boolean'],
            'reason_options' => ['nullable', 'array'],
            'reason_options.*' => ['string', 'max:120'],
        ], [
            'code.regex' => 'Le code doit être en snake_case (ex. client_hesitant).',
        ]);
    }

    private function codeFromName(string $name, int $companyId): string
    {
        $base = Str::slug($name, '_');
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower($base)) ?: 'statut';
        if (! preg_match('/^[a-z]/', $base)) {
            $base = 's_'.$base;
        }
        $base = substr($base, 0, 54);
        $code = $base;
        $i = 2;
        while (ConfirmationStatus::query()->forCompany($companyId)->where('code', $code)->exists()) {
            $code = $base.'_'.$i;
            $i++;
        }

        return $code;
    }
}
