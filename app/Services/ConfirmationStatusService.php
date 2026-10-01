<?php

namespace App\Services;

use App\Models\ConfirmationStatus;
use Illuminate\Database\Eloquent\Builder;

class ConfirmationStatusService
{
    /** @return list<array<string, mixed>> */
    public function activeFilters(): array
    {
        return ConfirmationStatus::query()
            ->forFilters()
            ->get()
            ->map(fn (ConfirmationStatus $status) => $status->toApiArray())
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function allForSettings(): array
    {
        return ConfirmationStatus::query()
            ->ordered()
            ->get()
            ->map(fn (ConfirmationStatus $status) => $status->toApiArray())
            ->values()
            ->all();
    }

    public function applyFilter(Builder $query, string $filterCode): void
    {
        $status = ConfirmationStatus::findByCode($filterCode);

        if (! $status) {
            $query->where('confirmation_status', ConfirmationStatus::defaultCode());

            return;
        }

        if ($status->queue_behavior === ConfirmationStatus::BEHAVIOR_DUE_QUEUE) {
            $postponedCodes = ConfirmationStatus::codesWithBehavior(ConfirmationStatus::BEHAVIOR_FUTURE_ONLY);

            $query->where(function ($q) use ($status, $postponedCodes) {
                $q->where('confirmation_status', $status->code);

                if ($postponedCodes !== []) {
                    $q->orWhere(function ($due) use ($postponedCodes) {
                        $due->whereIn('confirmation_status', $postponedCodes)
                            ->whereNotNull('postponed_until')
                            ->where('postponed_until', '<=', now());
                    });
                }
            });

            return;
        }

        if ($status->queue_behavior === ConfirmationStatus::BEHAVIOR_FUTURE_ONLY) {
            $query->where('confirmation_status', $status->code)
                ->where(function ($q) {
                    $q->whereNull('postponed_until')
                        ->orWhere('postponed_until', '>', now());
                });

            return;
        }

        $query->where('confirmation_status', $status->code);
    }

    /** @return array<string, int> */
    public function filterCounts(Builder $baseQuery): array
    {
        $counts = [];

        foreach (ConfirmationStatus::query()->forFilters()->get() as $status) {
            $q = (clone $baseQuery);
            $this->applyFilter($q, $status->code);
            $counts[$status->code] = $q->count();
        }

        return $counts;
    }
}
