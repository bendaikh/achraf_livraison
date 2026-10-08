<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ConfirmationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ConfirmationStatusService
{
    /** @return list<array<string, mixed>> */
    public function activeFilters(?int $companyId = null): array
    {
        $companyId = ConfirmationStatus::resolveCompanyId($companyId);

        return ConfirmationStatus::cachedAll($companyId)
            ->filter(fn (ConfirmationStatus $status) => $status->is_active && $status->show_in_filters)
            ->map(fn (ConfirmationStatus $status) => $status->toApiArray())
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function activeAll(?int $companyId = null): array
    {
        $companyId = ConfirmationStatus::resolveCompanyId($companyId);

        return ConfirmationStatus::cachedAll($companyId)
            ->filter(fn (ConfirmationStatus $status) => $status->is_active)
            ->map(fn (ConfirmationStatus $status) => $status->toApiArray())
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function allForSettings(?int $companyId = null): array
    {
        $companyId = ConfirmationStatus::resolveCompanyId($companyId);
        $usage = $this->usageMap($companyId);

        return ConfirmationStatus::query()
            ->forCompany($companyId)
            ->ordered()
            ->get()
            ->map(function (ConfirmationStatus $status) use ($usage) {
                $row = $usage[$status->code] ?? ['orders' => 0, 'history' => 0];

                return $status->toApiArray($row['orders'], $row['history']);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{orders: int, history: int}>
     */
    public function usageMap(int $companyId): array
    {
        $orders = \App\Models\Order::query()
            ->withoutGlobalScope('not_deleted')
            ->where('company_id', $companyId)
            ->selectRaw('confirmation_status, COUNT(*) as c')
            ->groupBy('confirmation_status')
            ->pluck('c', 'confirmation_status');

        $history = \App\Models\OrderStatusHistory::query()
            ->where('kind', 'confirmation')
            ->whereIn('order_id', \App\Models\Order::query()->withoutGlobalScope('not_deleted')->where('company_id', $companyId)->select('id'))
            ->selectRaw('status_code, COUNT(*) as c')
            ->groupBy('status_code')
            ->pluck('c', 'status_code');

        $codes = $orders->keys()->merge($history->keys())->unique();
        $map = [];
        foreach ($codes as $code) {
            $map[(string) $code] = [
                'orders' => (int) ($orders[$code] ?? 0),
                'history' => (int) ($history[$code] ?? 0),
            ];
        }

        return $map;
    }

    public function isUsed(ConfirmationStatus $status): bool
    {
        $usage = $this->usageMap((int) $status->company_id);
        $row = $usage[$status->code] ?? ['orders' => 0, 'history' => 0];

        return ($row['orders'] + $row['history']) > 0;
    }

    public function applyFilter(Builder $query, string $filterCode, ?int $companyId = null): void
    {
        $companyId = ConfirmationStatus::resolveCompanyId($companyId);
        $status = ConfirmationStatus::findByCode($filterCode, $companyId);

        if (! $status) {
            $query->where('confirmation_status', ConfirmationStatus::defaultCode($companyId));

            return;
        }

        if ($status->queue_behavior === ConfirmationStatus::BEHAVIOR_DUE_QUEUE || $status->is_default) {
            $recallCodes = ConfirmationStatus::cachedAll($companyId)
                ->filter(fn (ConfirmationStatus $item) => ! $item->is_terminal && ! $item->is_final && $item->code !== $status->code)
                ->pluck('code')
                ->values()
                ->all();

            $query->where(function ($q) use ($status, $recallCodes) {
                $q->where('confirmation_status', $status->code);
                if ($recallCodes !== []) {
                    $q->orWhere(function ($due) use ($recallCodes) {
                        $due->whereIn('confirmation_status', $recallCodes)
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

    /**
     * Counts for every active status, from a single GROUP BY on confirmation_status.
     *
     * @return array<string, int>
     */
    public function filterCounts(Builder $baseQuery, ?int $companyId = null): array
    {
        $companyId = ConfirmationStatus::resolveCompanyId($companyId);
        $rows = $this->groupedStatusRows($baseQuery);
        $statuses = ConfirmationStatus::cachedAll($companyId)->where('is_active', true);
        $counts = [];

        foreach ($statuses as $status) {
            if ($status->queue_behavior === ConfirmationStatus::BEHAVIOR_DUE_QUEUE || $status->is_default) {
                $total = (int) ($rows[$status->code]['total'] ?? 0);
                foreach ($statuses as $other) {
                    if ($other->code === $status->code || $other->is_terminal || $other->is_final) {
                        continue;
                    }
                    $total += (int) ($rows[$other->code]['due'] ?? 0);
                }
                $counts[$status->code] = $total;

                continue;
            }

            if ($status->queue_behavior === ConfirmationStatus::BEHAVIOR_FUTURE_ONLY) {
                $counts[$status->code] = (int) ($rows[$status->code]['open'] ?? 0);

                continue;
            }

            $counts[$status->code] = (int) ($rows[$status->code]['total'] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<string, array{total: int, due: int, open: int}>
     */
    public function groupedStatusRows(Builder $baseQuery): array
    {
        $query = clone $baseQuery;
        $query->getQuery()->columns = null;
        $query->reorder();
        $now = now()->toDateTimeString();
        $rows = $query
            ->selectRaw(
                'confirmation_status, COUNT(*) as total, '
                .'SUM(CASE WHEN postponed_until IS NOT NULL AND postponed_until <= ? THEN 1 ELSE 0 END) as due_n, '
                .'SUM(CASE WHEN postponed_until IS NULL OR postponed_until > ? THEN 1 ELSE 0 END) as open_n',
                [$now, $now],
            )
            ->groupBy('confirmation_status')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->confirmation_status] = [
                'total' => (int) $row->total,
                'due' => (int) $row->due_n,
                'open' => (int) $row->open_n,
            ];
        }

        return $map;
    }

    public function applyRecallBucket(Builder $query, string $bucket, ?int $companyId = null): void
    {
        [$start, $end] = $this->todayBounds($companyId);
        $now = now();

        match ($bucket) {
            'overdue' => $query->whereNotNull('postponed_until')->where('postponed_until', '<', $now),
            'today' => $query->whereNotNull('postponed_until')->where('postponed_until', '>=', $now)->where('postponed_until', '<=', $end),
            'upcoming' => $query->whereNotNull('postponed_until')->where('postponed_until', '>', $end),
            default => null,
        };
    }

    /** @return array{overdue: int, today: int, upcoming: int} */
    public function recallBuckets(Builder $baseQuery, ?int $companyId = null): array
    {
        $companyId = ConfirmationStatus::resolveCompanyId($companyId);
        [$start, $end] = $this->todayBounds($companyId);
        $now = now()->toDateTimeString();
        $endSql = $end->toDateTimeString();

        $query = clone $baseQuery;
        $query->getQuery()->columns = null;
        $query->reorder();
        $row = $query
            ->whereNotNull('postponed_until')
            ->selectRaw(
                'SUM(CASE WHEN postponed_until < ? THEN 1 ELSE 0 END) as overdue_n, '
                .'SUM(CASE WHEN postponed_until >= ? AND postponed_until <= ? THEN 1 ELSE 0 END) as today_n, '
                .'SUM(CASE WHEN postponed_until > ? THEN 1 ELSE 0 END) as upcoming_n',
                [$now, $now, $endSql, $endSql],
            )
            ->first();

        return [
            'overdue' => (int) ($row->overdue_n ?? 0),
            'today' => (int) ($row->today_n ?? 0),
            'upcoming' => (int) ($row->upcoming_n ?? 0),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function todayBounds(?int $companyId = null): array
    {
        $companyId = ConfirmationStatus::resolveCompanyId($companyId);
        $tz = Company::query()->find($companyId)?->timezoneOrDefault() ?? 'Africa/Casablanca';
        $local = now()->timezone($tz);

        return [
            $local->copy()->startOfDay()->utc(),
            $local->copy()->endOfDay()->utc(),
        ];
    }
}
