<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentCommission;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Utilisateurs (T6): back-office users with service, manager, access and remuneration. */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = User::query()->where('role', '!=', User::ROLE_LIVREUR)->with(['service:id,name', 'manager:id,name'])->orderBy('name');
        if ($request->filled('service_id')) {
            $q->where('service_id', $request->integer('service_id'));
        }
        if ($request->filled('q')) {
            $s = trim((string) $request->query('q'));
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"));
        }

        return response()->json([
            'data' => $q->get()->map(fn (User $u) => $this->payload($u))->values(),
            'options' => $this->options(),
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['service:id,name', 'manager:id,name']);
        $lines = $user->commissions()->with(['order', 'validator:id,name', 'payer:id,name'])->latest('generated_at')->latest('id')->limit(200)->get();
        $totals = AgentCommission::query()->where('user_id', $user->id)->selectRaw('state, SUM(amount) as total, COUNT(*) as n')->groupBy('state')->get()->keyBy('state');

        return response()->json([
            'data' => $this->payload($user),
            'commissions' => $lines->map->toPayload()->values(),
            'totals' => collect(config('commissions.states'))->map(fn ($label, $state) => ['label' => $label, 'amount' => (float) ($totals[$state]->total ?? 0), 'count' => (int) ($totals[$state]->n ?? 0)]),
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $user = new User;
        $user->fill($data + ['company_id' => $request->user()->resolveCompanyId()]);
        $user->password = $data['password'];
        $user->save();

        return response()->json(['message' => 'Utilisateur créé.', 'data' => $this->payload($user->fresh(['service', 'manager']))], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_if($user->isLivreur(), 404);
        if ($user->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Seul un super admin peut modifier ce compte.'], 403);
        }
        $data = $this->validated($request, $user);
        if ($user->id === $request->user()->id && array_key_exists('is_active', $data) && ! $data['is_active']) {
            return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte.'], 422);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->fill($data)->save();

        return response()->json(['message' => 'Utilisateur enregistré.', 'data' => $this->payload($user->fresh(['service', 'manager']))]);
    }

    protected function validated(Request $request, ?User $user = null): array
    {
        $roles = [User::ROLE_ADMIN, User::ROLE_USER];
        if ($request->user()->isSuperAdmin()) {
            $roles[] = User::ROLE_SUPERADMIN;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', Rule::in($roles)],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id', Rule::notIn([$user?->id])],
            'is_active' => ['sometimes', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:100'],
            'commission_mode' => ['nullable', Rule::in(array_keys(config('commissions.modes')))],
            'commission_value' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'commission_trigger' => ['nullable', Rule::in(array_keys(config('commissions.triggers')))],
        ], [
            'email.unique' => 'Cet email est déjà utilisé.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'manager_id.not_in' => 'Un utilisateur ne peut pas être son propre responsable.',
        ]);
        $data['commission_mode'] = $data['commission_mode'] ?? 'none';
        if (($data['commission_mode'] ?? 'none') === 'percent' && ($data['commission_value'] ?? 0) > 100) {
            abort(response()->json(['message' => 'Un pourcentage ne peut pas dépasser 100 %.', 'errors' => ['commission_value' => ['Maximum 100 %.']]], 422));
        }
        if ($data['commission_mode'] === 'none') {
            $data['commission_value'] = null;
            $data['commission_trigger'] = null;
        }

        return $data;
    }

    protected function options(): array
    {
        return [
            'roles' => collect([User::ROLE_ADMIN => 'Admin', User::ROLE_USER => 'Agent'])->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'services' => Service::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'is_active']),
            'managers' => User::query()->where('role', '!=', User::ROLE_LIVREUR)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'commission_modes' => collect(config('commissions.modes'))->map(fn ($m, $k) => ['value' => $k, 'label' => $m['label'], 'kind' => $m['kind'], 'default_trigger' => $m['default_trigger'] ?? null])->values(),
            'commission_triggers' => collect(config('commissions.triggers'))->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
        ];
    }

    public static function payload(User $u): array
    {
        $mode = $u->commission_mode ?: 'none';

        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role,
            'role_label' => $u->role === User::ROLE_USER ? 'Agent' : $u->roleLabel(),
            'service_id' => $u->service_id,
            'service_name' => $u->service?->name,
            'manager_id' => $u->manager_id,
            'manager_name' => $u->manager?->name,
            'is_active' => $u->is_active !== false,
            'commission_mode' => $mode,
            'commission_mode_label' => config("commissions.modes.{$mode}.label"),
            'commission_value' => $u->commission_value,
            'commission_trigger' => $u->commission_trigger,
            'commission_trigger_effective' => $u->commissionTrigger(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
