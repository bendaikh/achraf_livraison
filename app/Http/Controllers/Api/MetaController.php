<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryStatusResource;
use App\Models\ConfirmationStatus;
use App\Models\DeliveryStatus;
use App\Models\Driver;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
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
            // Configurable confirmation statuses (confirmation_statuses table).
            'confirmation_statuses' => ConfirmationStatus::query()->active()->ordered()->get()
                ->map(fn (ConfirmationStatus $s) => ['value' => $s->code, 'label' => $s->name, 'color' => $s->color, 'type' => $s->type])
                ->values(),
            'mission_types' => Catalog::toOptions(Catalog::MISSION_TYPES),
            'mission_statuses' => Catalog::toOptions(Catalog::MISSION_STATUSES),
            'required_field_catalog' => Catalog::toOptions(Catalog::REQUIRED_FIELDS),
            'status_mission_types' => Catalog::STATUS_MISSION_TYPES,
            'payment_methods' => Catalog::toOptions(Catalog::PAYMENT_METHODS),
            'drivers' => Driver::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'default_tariffs' => Setting::defaultTariffs(),
            'users' => User::query()->where('role', '!=', User::ROLE_LIVREUR)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'service_id']),
            'services' => Service::query()->where('is_active', true)->orderBy('position')->orderBy('name')->get(['id', 'name']),
            'driver_actions' => Catalog::toOptions(Catalog::DRIVER_ACTIONS),
            'current_user' => $user ? ['id' => $user->id, 'name' => $user->name, 'role' => $user->role] : null,
        ]);
    }
}
