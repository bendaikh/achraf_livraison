<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryStatusResource;
use App\Models\DeliveryStatus;
use App\Models\Driver;
use App\Support\Catalog;
use App\Support\CurrentUser;

class MetaController extends Controller
{
    /** Everything the SPA needs to render lists/filters — no list is hard-coded in React. */
    public function show()
    {
        $user = CurrentUser::get();

        return response()->json([
            'statuses' => DeliveryStatusResource::collection(DeliveryStatus::active()->ordered()->get()),
            'status_categories' => Catalog::toOptions(Catalog::STATUS_CATEGORIES),
            'confirmation_statuses' => Catalog::toOptions(Catalog::CONFIRMATION_STATUSES),
            'mission_types' => Catalog::toOptions(Catalog::MISSION_TYPES),
            'mission_statuses' => Catalog::toOptions(Catalog::MISSION_STATUSES),
            'payment_methods' => Catalog::toOptions(Catalog::PAYMENT_METHODS),
            'drivers' => Driver::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'current_user' => $user ? ['id' => $user->id, 'name' => $user->name] : null,
        ]);
    }
}
