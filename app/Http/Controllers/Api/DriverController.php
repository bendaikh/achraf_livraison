<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use App\Models\Setting;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function index(Request $request)
    {
        $q = Driver::query()->orderByDesc('is_active')->orderBy('name');
        if ($request->boolean('active')) {
            $q->where('is_active', true);
        }
        if ($search = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }

        return DriverResource::collection($q->get());
    }

    public function show(Driver $driver)
    {
        return new DriverResource($driver);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        // Company default tariffs prefill anything not provided for a new driver.
        foreach (Setting::defaultTariffs() as $type => $amount) {
            $column = Driver::TARIFF_COLUMNS[$type];
            if (! isset($data[$column]) || $data[$column] === null || $data[$column] === '') {
                $data[$column] = $amount;
            }
        }
        $driver = Driver::create($data);

        return (new DriverResource($driver))->response()->setStatusCode(201);
    }

    public function update(Request $request, Driver $driver)
    {
        $data = $this->validated($request, true);
        foreach (Driver::TARIFF_COLUMNS as $column) {
            if (array_key_exists($column, $data) && $data[$column] === null) {
                $data[$column] = 0;
            }
        }
        // Only the driver row changes: missions keep their own price snapshot.
        $driver->update($data);

        return new DriverResource($driver->fresh());
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'vehicle' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tariff_livraison' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tariff_ramassage' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tariff_depot_partenaire' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tariff_retour' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tariff_echange' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
    }
}
