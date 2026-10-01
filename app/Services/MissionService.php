<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Mission;
use App\Models\MissionStatusHistory;
use App\Support\Catalog;
use App\Support\CurrentUser;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($data) {
            $driverId = $data['driver_id'] ?? null;
            unset($data['driver_id']);
            $mission = new Mission($data + ['status' => 'a_faire']);
            $mission->save();
            $this->log($mission, 'created', $mission->status, 'Mission '.(Catalog::MISSION_TYPES[$mission->type] ?? $mission->type).' créée');

            if ($driverId) {
                $this->assign($mission, (int) $driverId);
            }

            return $mission;
        });
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
            $mission->save();
            $this->log($mission, 'assigned', $mission->status, "Attribuée à {$driver->name} (tarif ".number_format((float) $mission->driver_price, 2, ',', ' ').' DH)');
        } else {
            $mission->driver_id = null;
            $mission->driver_price = null;
            $mission->assigned_at = null;
            $mission->save();
            $this->log($mission, 'assigned', $mission->status, 'Livreur retiré');
        }

        return $mission;
    }

    public function changeStatus(Mission $mission, string $status, ?string $note = null): Mission
    {
        if ($mission->closing_id) {
            throw ValidationException::withMessages(['status' => 'Mission déjà clôturée : son statut ne peut plus changer.']);
        }
        if ($mission->status === $status) {
            return $mission;
        }
        $mission->status = $status;
        $mission->completed_at = $status === 'terminee' ? ($mission->completed_at ?? now()) : null;
        $mission->save();
        $this->log($mission, 'status', $status, Catalog::MISSION_STATUSES[$status]['label'] ?? $status, $note);

        return $mission;
    }

    public function isLocked(Mission $mission): bool
    {
        return $mission->status === 'terminee' || ! empty($mission->closing_id);
    }

    public function log(Mission $mission, string $event, ?string $status, string $label, ?string $note = null): void
    {
        MissionStatusHistory::create([
            'mission_id' => $mission->id,
            'event' => $event,
            'status' => $status,
            'label' => $label,
            'note' => $note,
            'user_id' => CurrentUser::id(),
        ]);
    }
}
