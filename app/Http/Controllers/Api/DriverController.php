<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
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
        $driver = Driver::create($this->validated($request));

        return (new DriverResource($driver))->response()->setStatusCode(201);
    }

    public function update(Request $request, Driver $driver)
    {
        $driver->update($this->validated($request, true));

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
        ]);
    }
}
