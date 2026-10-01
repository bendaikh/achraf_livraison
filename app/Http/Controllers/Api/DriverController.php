<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Livreurs (admin): each driver has a login account (role livreur), activity counters,
 * COD held and individual mission tariffs.
 */
class DriverController extends Controller
{
    public function index(Request $request)
    {
        $q = Driver::query()->with('user:id,name,email,role')->orderByDesc('is_active')->orderBy('name');

        $active = $request->query('active');
        if ($active === '1' || $active === 'true') {
            $q->where('is_active', true);
        } elseif ($active === '0' || $active === 'false') {
            $q->where('is_active', false);
        }
        $search = trim((string) ($request->query('q') ?? $request->query('search', '')));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($uq) => $uq->where('email', 'like', "%{$search}%")));
        }

        $drivers = $q->get();
        if ($request->boolean('with_stats', true)) {
            $drivers->each(fn (Driver $d) => $d->setAttribute('stats', $this->stats($d)));
        }

        return DriverResource::collection($drivers);
    }

    /** Light list used by the Affectation screen. */
    public function active(): JsonResponse
    {
        return response()->json([
            'drivers' => Driver::query()->active()->orderBy('name')->get(['id', 'name', 'phone', 'is_active']),
        ]);
    }

    public function show(Driver $driver)
    {
        $driver->load('user:id,name,email,role')->setAttribute('stats', $this->stats($driver));

        return new DriverResource($driver);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        // Company default tariffs prefill anything not provided for a new driver.
        foreach (Setting::defaultTariffs() as $type => $amount) {
            $column = Driver::TARIFF_COLUMNS[$type];
            if (! isset($data[$column]) || $data[$column] === '') {
                $data[$column] = $amount;
            }
        }

        $driver = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => User::ROLE_LIVREUR,
                'email_verified_at' => now(),
            ]);

            return Driver::create(collect($data)->except(['email', 'password'])->all() + ['user_id' => $user->id]);
        });

        return (new DriverResource($this->fresh($driver)))->additional(['message' => 'Livreur créé.'])
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, Driver $driver)
    {
        $data = $this->validated($request, $driver);
        foreach (Driver::TARIFF_COLUMNS as $column) {
            if (array_key_exists($column, $data) && $data[$column] === null) {
                $data[$column] = 0;
            }
        }

        DB::transaction(function () use ($data, $driver) {
            // Only the driver row changes: missions keep their own price snapshot.
            $driver->update(collect($data)->except(['email', 'password'])->all());

            $userData = array_filter([
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'password' => ! empty($data['password']) ? $data['password'] : null,
            ]);
            if ($userData === []) {
                return;
            }
            if ($driver->user) {
                $driver->user->fill($userData)->save();
            } elseif (! empty($data['email']) && ! empty($data['password'])) {
                $user = User::query()->create($userData + [
                    'name' => $driver->name,
                    'role' => User::ROLE_LIVREUR,
                    'email_verified_at' => now(),
                ]);
                $driver->forceFill(['user_id' => $user->id])->save();
            }
        });

        return (new DriverResource($this->fresh($driver)))->additional(['message' => 'Livreur mis à jour.']);
    }

    protected function fresh(Driver $driver): Driver
    {
        $driver = $driver->fresh('user');
        $driver->setAttribute('stats', $this->stats($driver));

        return $driver;
    }

    /** @return array<string, int|float> */
    protected function stats(Driver $driver): array
    {
        return [
            'orders_assigned' => $driver->assignedOrdersCount(),
            'orders_in_progress' => $driver->inProgressOrdersCount(),
            'orders_delivered' => $driver->deliveredOrdersCount(),
            'orders_failed' => $driver->failedOrdersCount(),
            'cod_held' => round($driver->codHeldAmount(), 2),
        ];
    }

    protected function validated(Request $request, ?Driver $driver = null): array
    {
        $partial = $driver !== null;

        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => [
                $partial ? 'sometimes' : 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($driver?->user_id),
            ],
            'password' => [$partial ? 'nullable' : 'required', 'string', 'min:8', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'vehicle' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tariff_livraison' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tariff_ramassage' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tariff_depot_partenaire' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tariff_retour' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tariff_echange' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [
            'name.required' => 'Le nom du livreur est obligatoire.',
            'email.required' => 'L’email de connexion est obligatoire.',
            'email.unique' => 'Cet email est déjà utilisé.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ]);
    }
}
