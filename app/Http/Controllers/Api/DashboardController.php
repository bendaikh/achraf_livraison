<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function show(Request $request, DashboardService $dashboard)
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(array_keys(DashboardService::PERIODS))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
        ]);

        return response()->json(['data' => $dashboard->build(
            $data['period'] ?? 'today',
            $data['from'] ?? null,
            $data['to'] ?? null,
            isset($data['driver_id']) ? (int) $data['driver_id'] : null,
        )]);
    }
}
