<?php

namespace App\Http\Controllers;

use App\Models\ConfirmationStatus;
use App\Models\Order;
use App\Services\ConfirmationStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * API ready for Paramètres → Statuts de confirmation.
 * UI page can be built later on top of these endpoints.
 */
class ConfirmationStatusController extends Controller
{
    public function __construct(
        private readonly ConfirmationStatusService $statuses,
    ) {}

    /** Active statuses for Confirmation filters / badges. */
    public function index(): JsonResponse
    {
        return response()->json([
            'statuses' => $this->statuses->activeFilters(),
        ]);
    }

    /** Full list including inactive — for future Settings UI. */
    public function settingsIndex(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'Accès réservé aux administrateurs.');
        }

        return response()->json([
            'statuses' => $this->statuses->allForSettings(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'Accès réservé aux administrateurs.');
        }

        $data = $this->validateStatus($request);
        $maxOrder = (int) ConfirmationStatus::query()->max('sort_order');

        $status = ConfirmationStatus::query()->create(array_merge($data, [
            'sort_order' => $data['sort_order'] ?? ($maxOrder + 10),
            'type' => $data['type'] ?? ConfirmationStatus::TYPE_CUSTOM,
            'is_default' => false,
            'is_terminal' => (bool) ($data['is_terminal'] ?? false),
            'show_in_filters' => (bool) ($data['show_in_filters'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'queue_behavior' => $data['queue_behavior'] ?? null,
        ]));

        ConfirmationStatus::flushCache();

        return response()->json([
            'status' => $status->fresh()->toApiArray(),
            'message' => 'Statut créé.',
        ], 201);
    }

    public function update(Request $request, ConfirmationStatus $confirmationStatus): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'Accès réservé aux administrateurs.');
        }

        $data = $this->validateStatus($request, $confirmationStatus);

        // Prevent removing the only default status without replacement.
        if (array_key_exists('is_default', $data) && $data['is_default'] === false && $confirmationStatus->is_default) {
            $others = ConfirmationStatus::query()
                ->where('id', '!=', $confirmationStatus->id)
                ->where('is_default', true)
                ->exists();
            if (! $others) {
                return response()->json([
                    'message' => 'Au moins un statut par défaut est requis.',
                ], 422);
            }
        }

        if (! empty($data['is_default'])) {
            ConfirmationStatus::query()
                ->where('id', '!=', $confirmationStatus->id)
                ->update(['is_default' => false]);
        }

        // System action codes stay immutable so confirmation actions keep working.
        $systemCodes = [
            Order::CONFIRMATION_TO_CONFIRM,
            Order::CONFIRMATION_NO_ANSWER,
            Order::CONFIRMATION_POSTPONED,
            Order::CONFIRMATION_CONFIRMED,
            Order::CONFIRMATION_CANCELLED,
        ];
        if (in_array($confirmationStatus->code, $systemCodes, true)) {
            unset($data['code']);
        }

        $confirmationStatus->fill($data)->save();
        ConfirmationStatus::flushCache();

        return response()->json([
            'status' => $confirmationStatus->fresh()->toApiArray(),
            'message' => 'Statut mis à jour.',
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'Accès réservé aux administrateurs.');
        }

        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'exists:confirmation_statuses,id'],
        ]);

        DB::transaction(function () use ($data) {
            foreach (array_values($data['order']) as $index => $id) {
                ConfirmationStatus::query()
                    ->where('id', $id)
                    ->update(['sort_order' => ($index + 1) * 10]);
            }
        });

        ConfirmationStatus::flushCache();

        return response()->json([
            'statuses' => $this->statuses->allForSettings(),
            'message' => 'Ordre mis à jour.',
        ]);
    }

    private function validateStatus(Request $request, ?ConfirmationStatus $existing = null): array
    {
        return $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:120'],
            'code' => [
                $existing ? 'sometimes' : 'required',
                'string',
                'max:64',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('confirmation_statuses', 'code')->ignore($existing?->id),
            ],
            'color' => [$existing ? 'sometimes' : 'required', 'string', 'max:32'],
            'icon' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'type' => ['nullable', 'string', 'max:32'],
            'filter_label' => ['nullable', 'string', 'max:120'],
            'show_in_filters' => ['sometimes', 'boolean'],
            'is_terminal' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'queue_behavior' => ['nullable', 'string', Rule::in([
                ConfirmationStatus::BEHAVIOR_DUE_QUEUE,
                ConfirmationStatus::BEHAVIOR_FUTURE_ONLY,
            ])],
        ], [
            'code.regex' => 'Le code doit être en snake_case (ex. client_hesitant).',
        ]);
    }
}
