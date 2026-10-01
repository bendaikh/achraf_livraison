<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Mission;
use Illuminate\Validation\ValidationException;

/**
 * Mission assignment & tariff snapshot.
 *
 * Rule: the driver's tariff for the mission type is copied into missions.driver_price
 * at the moment the mission is assigned. Changing a driver's tariffs later NEVER
 * touches existing missions, so past earnings and closings stay unchanged.
 */
class MissionService
{
    public function create(array $data): Mission
    {
        $driverId = $data['driver_id'] ?? null;
        unset($data['driver_id']);
        $mission = new Mission($data + ['status' => 'a_faire']);
        $mission->save();

        if ($driverId) {
            $this->assign($mission, (int) $driverId);
        }

        return $mission;
    }

    public function assign(Mission $mission, ?int $driverId): Mission
    {
        if ((int) $mission->driver_id === (int) $driverId && $mission->assigned_at) {
            return $mission; // same driver: keep the original snapshot
        }
        if ($this->isLocked($mission)) {
            throw ValidationException::withMessages([
                'driver_id' => 'Mission terminée ou clôturée : le livreur et son tarif ne peuvent plus être modifiés.',
            ]);
        }

        if ($driverId) {
            $driver = Driver::findOrFail($driverId);
            $mission->driver_id = $driver->id;
            $mission->driver_price = $driver->tariffFor($mission->type);
            $mission->assigned_at = now();
        } else {
            $mission->driver_id = null;
            $mission->driver_price = null;
            $mission->assigned_at = null;
        }
        $mission->save();

        return $mission;
    }

    public function isLocked(Mission $mission): bool
    {
        return $mission->status === 'terminee' || ! empty($mission->closing_id);
    }
}
