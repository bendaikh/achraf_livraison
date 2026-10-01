<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $active = $request->query('active');

        $query = Driver::query()->with('user:id,name,email,role')->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('email', 'like', "%{$search}%"));
            });
        }

        if ($active === '1' || $active === 'true') {
            $query->where('is_active', true);
        } elseif ($active === '0' || $active === 'false') {
            $query->where('is_active', false);
        }

        $drivers = $query->get()->map(fn (Driver $driver) => $this->payload($driver));

        return response()->json([
            'drivers' => $drivers,
        ]);
    }

    public function active(): JsonResponse
    {
        $drivers = Driver::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'is_active']);

        return response()->json([
            'drivers' => $drivers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ], [
            'name.required' => 'Le nom du livreur est obligatoire.',
            'email.required' => 'L’email de connexion est obligatoire.',
            'email.unique' => 'Cet email est déjà utilisé.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ]);

        $driver = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => User::ROLE_LIVREUR,
                'email_verified_at' => now(),
            ]);

            return Driver::query()->create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'phone' => isset($data['phone']) ? trim((string) $data['phone']) : null,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });

        return response()->json([
            'driver' => $this->payload($driver->fresh('user')),
            'message' => 'Livreur créé.',
        ], 201);
    }

    public function update(Request $request, Driver $driver): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($driver->user_id),
            ],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
        ]);

        DB::transaction(function () use ($data, $driver) {
            $driver->fill([
                'name' => $data['name'] ?? $driver->name,
                'phone' => array_key_exists('phone', $data)
                    ? (trim((string) ($data['phone'] ?? '')) ?: null)
                    : $driver->phone,
                'is_active' => $data['is_active'] ?? $driver->is_active,
            ])->save();

            if ($driver->user) {
                $userData = [];
                if (isset($data['name'])) {
                    $userData['name'] = $data['name'];
                }
                if (isset($data['email'])) {
                    $userData['email'] = $data['email'];
                }
                if (! empty($data['password'])) {
                    $userData['password'] = $data['password'];
                }
                if ($userData !== []) {
                    $driver->user->fill($userData)->save();
                }
            }
        });

        return response()->json([
            'driver' => $this->payload($driver->fresh('user')),
            'message' => 'Livreur mis à jour.',
        ]);
    }

    private function payload(Driver $driver): array
    {
        return [
            'id' => $driver->id,
            'name' => $driver->name,
            'phone' => $driver->phone,
            'is_active' => (bool) $driver->is_active,
            'email' => $driver->user?->email,
            'user_id' => $driver->user_id,
            'orders_assigned' => $driver->assignedOrdersCount(),
            'orders_in_progress' => $driver->inProgressOrdersCount(),
            'orders_delivered' => $driver->deliveredOrdersCount(),
            'cod_held' => round($driver->codHeldAmount(), 2),
        ];
    }
}
