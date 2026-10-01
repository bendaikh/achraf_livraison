<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Closing;
use App\Models\Driver;
use App\Services\ClosingService;
use Illuminate\Http\Request;

class ClosingController extends Controller
{
    public function __construct(protected ClosingService $service) {}

    public function index(Request $request)
    {
        $closings = Closing::query()->with(['driver', 'user'])
            ->when($request->filled('driver_id'), fn ($q) => $q->where('driver_id', $request->integer('driver_id')))
            ->orderByDesc('closed_at')->limit(100)->get();

        return response()->json(['data' => $closings->map(fn (Closing $c) => $this->present($c))]);
    }

    public function pending()
    {
        return response()->json(['data' => $this->service->pendingByDriver()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'cod_remitted' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $closing = $this->service->close(
            Driver::findOrFail($data['driver_id']),
            isset($data['cod_remitted']) ? (float) $data['cod_remitted'] : null,
            $data['note'] ?? null,
        );

        return response()->json(['data' => $this->present($closing->load(['driver', 'user']))], 201);
    }

    protected function present(Closing $c): array
    {
        return [
            'id' => $c->id,
            'driver' => $c->driver ? ['id' => $c->driver->id, 'name' => $c->driver->name] : null,
            'closing_date' => $c->closing_date?->format('Y-m-d'),
            'cod_expected' => (float) $c->cod_expected,
            'cod_remitted' => (float) $c->cod_remitted,
            'gap' => round((float) $c->cod_expected - (float) $c->cod_remitted, 2),
            'commissions_total' => (float) $c->commissions_total,
            'orders_count' => $c->orders_count,
            'missions_count' => $c->missions_count,
            'note' => $c->note,
            'user_name' => $c->user?->name,
            'closed_at' => $c->closed_at?->toIso8601String(),
        ];
    }
}
