<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\SavRequest;
use App\Services\Sav\SavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** T7 — Espace livreur → Retours & échanges. The driver can never receive at depot nor close. */
class DriverSavController extends Controller
{
    public function __construct(private SavService $sav) {}

    public function index(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $filter = $request->query('filter', 'active');
        $q = SavRequest::query()->where('driver_id', $driver->id)->latest('assigned_at');
        $filter === 'history' ? $q->whereNotIn('status', config('sav.driver_open')) : $q->whereIn('status', config('sav.driver_open'));

        return response()->json([
            'data' => $q->limit(100)->get()->map(fn ($s) => $this->sav->payload($s, 'driver'))->values(),
            'counts' => [
                'active' => SavRequest::query()->where('driver_id', $driver->id)->whereIn('status', config('sav.driver_open'))->count(),
                'history' => SavRequest::query()->where('driver_id', $driver->id)->whereNotIn('status', config('sav.driver_open'))->count(),
            ],
        ]);
    }

    public function action(Request $request, SavRequest $sav): JsonResponse
    {
        $driver = $this->driver($request);
        abort_unless((int) $sav->driver_id === $driver->id, 403, 'Cette demande ne vous est pas attribuée.');
        $data = $request->validate(['action' => ['required', 'string'], 'comment' => ['nullable', 'string', 'max:2000'], 'postponed_until' => ['nullable', 'date']]);
        $s = $this->sav->act($sav, $data['action'], 'driver', $request->user(), $data, $driver);

        return response()->json(['message' => SavRequest::statusLabel($s->status).'.', 'data' => $this->sav->payload($s, 'driver')]);
    }

    private function driver(Request $request): Driver
    {
        $user = $request->user();
        if ($user->isLivreur()) {
            $driver = $user->driver;
            if (! $driver || ! $driver->is_active) {
                throw ValidationException::withMessages(['driver' => 'Profil livreur inactif ou absent.']);
            }

            return $driver;
        }
        $id = $request->integer('driver_id');
        if ($id > 0) {
            return Driver::query()->findOrFail($id);
        }
        throw ValidationException::withMessages(['driver' => 'Profil livreur requis.']);
    }
}
