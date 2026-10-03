<?php

namespace App\Services\Clients;

use App\Models\ClientBlock;
use App\Models\ClientGroupMember;
use App\Models\DeliveryStatus;
use App\Models\Order;
use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * T9 — Clients are aggregated on the fly from orders, grouped by normalized phone (phone_key).
 * No separate client database: blocks / notes / groups reference the phone key.
 */
class ClientService
{
    public static function key(?string $phone): ?string
    {
        $k = PhoneNormalizer::digits($phone);

        return $k !== '' ? $k : null;
    }

    /** Aggregates per phone key as a query builder (derived table "c"). */
    public function aggregateQuery(): Builder
    {
        $codes = fn (array $cats) => DeliveryStatus::codesForCategories($cats) ?: ['__none__'];
        $in = function (array $list) {
            return implode(',', array_map(fn ($c) => DB::getPdo()->quote($c), $list));
        };
        $delivered = $in($codes(['succes']));
        $returned = $in($codes(['retour']));
        $cancelled = $in($codes(['annulation']));
        $date = 'COALESCE(shopify_created_at, created_at)';

        $agg = DB::table('orders')
            ->whereNotNull('phone_key')
            ->groupBy('phone_key')
            ->selectRaw("phone_key,
                MAX(id) as last_order_id,
                COUNT(*) as orders,
                SUM(total_price) as total,
                SUM(CASE WHEN delivery_status IN ($delivered) THEN total_price ELSE 0 END) as total_delivered,
                SUM(CASE WHEN delivery_status IN ($delivered) THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN delivery_status IN ($returned) THEN 1 ELSE 0 END) as returned,
                SUM(CASE WHEN confirmation_status = ? OR delivery_status IN ($cancelled) THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN confirmation_status = ? THEN 1 ELSE 0 END) as confirmed,
                MIN($date) as first_at,
                MAX($date) as last_at", [Order::CONFIRMATION_CANCELLED, Order::CONFIRMATION_CONFIRMED]);

        $rates = DB::query()->fromSub($agg, 'a')->selectRaw('a.*,
            CASE WHEN (a.delivered + a.returned) > 0 THEN a.returned * 100.0 / (a.delivered + a.returned) ELSE 0 END as return_rate,
            CASE WHEN a.confirmed > 0 THEN a.delivered * 100.0 / a.confirmed ELSE 0 END as delivery_rate,
            CASE WHEN a.orders > 0 THEN a.confirmed * 100.0 / a.orders ELSE 0 END as confirmation_rate,
            CASE WHEN a.orders > 0 THEN a.cancelled * 100.0 / a.orders ELSE 0 END as cancel_rate');

        return DB::query()->fromSub($rates, 'c')
            ->leftJoin('orders as lo', 'lo.id', '=', 'c.last_order_id')
            ->select('c.*', 'lo.customer_name', 'lo.email', 'lo.phone', 'lo.shipping_address');
    }

    public function applySegment(Builder $q, string $segment): Builder
    {
        $def = config("client_segments.segments.$segment");
        abort_unless($def, 404, 'Segment inconnu.');
        foreach ($def['rules'] as $metric => $bounds) {
            if ($metric === 'days_since_first' || $metric === 'days_since_last') {
                $col = $metric === 'days_since_first' ? 'c.first_at' : 'c.last_at';
                if (isset($bounds['max'])) {
                    $q->where($col, '>=', now()->subDays($bounds['max']));
                }
                if (isset($bounds['min'])) {
                    $q->where($col, '<=', now()->subDays($bounds['min']));
                }

                continue;
            }
            abort_unless(in_array($metric, ['orders', 'delivered', 'returned', 'cancelled', 'confirmed', 'return_rate', 'cancel_rate', 'delivery_rate', 'confirmation_rate', 'total'], true), 500, 'Métrique de segment inconnue.');
            // Numbers inlined (config values cast to float) so SQLite compares numerically.
            if (isset($bounds['min'])) {
                $q->whereRaw("c.$metric >= ".(float) $bounds['min']);
            }
            if (isset($bounds['max'])) {
                $q->whereRaw("c.$metric <= ".(float) $bounds['max']);
            }
        }

        return $q;
    }

    /** Filtered list query for the Clients screen. */
    public function listQuery(array $f): Builder
    {
        $q = $this->aggregateQuery();
        if (! empty($f['search'])) {
            $s = trim($f['search']);
            $digits = preg_replace('/\D/', '', $s);
            $q->where(function ($w) use ($s, $digits) {
                $w->where('lo.customer_name', 'like', "%$s%")->orWhere('lo.email', 'like', "%$s%");
                if (strlen($digits) >= 4) {
                    $w->orWhere('c.phone_key', 'like', '%'.ltrim($digits, '0').'%');
                }
            });
        }
        $tab = $f['tab'] ?? 'all';
        if ($tab === 'blocked') {
            $q->whereIn('c.phone_key', ClientBlock::query()->active()->select('phone_key'));
        } elseif ($tab === 'segment' && ! empty($f['segment'])) {
            $this->applySegment($q, $f['segment']);
        } elseif ($tab === 'group' && ! empty($f['group_id'])) {
            $q->whereIn('c.phone_key', ClientGroupMember::query()->where('client_group_id', $f['group_id'])->select('phone_key'));
        }
        $sorts = ['last_at' => 'c.last_at', 'orders' => 'c.orders', 'total' => 'c.total', 'return_rate' => 'c.return_rate', 'name' => 'lo.customer_name'];
        $sort = $sorts[$f['sort'] ?? 'last_at'] ?? 'c.last_at';
        $dir = ($f['dir'] ?? ($sort === 'lo.customer_name' ? 'asc' : 'desc')) === 'asc' ? 'asc' : 'desc';

        return $q->orderBy($sort, $dir)->orderBy('c.phone_key');
    }

    public function find(string $key): ?object
    {
        return $this->aggregateQuery()->where('c.phone_key', $key)->first();
    }

    /** Uniform row payload (list + card header). */
    public function payload(object $row): array
    {
        $address = is_string($row->shipping_address) ? json_decode($row->shipping_address, true) : (array) $row->shipping_address;
        $block = ClientBlock::activeFor($row->phone_key);

        return [
            'key' => $row->phone_key,
            'name' => $row->customer_name ?: 'Client sans nom',
            'phone' => $row->phone,
            'email' => $row->email,
            'city' => $address['city'] ?? null,
            'orders' => (int) $row->orders,
            'total' => round((float) $row->total, 2),
            'total_delivered' => round((float) $row->total_delivered, 2),
            'delivered' => (int) $row->delivered,
            'returned' => (int) $row->returned,
            'cancelled' => (int) $row->cancelled,
            'confirmed' => (int) $row->confirmed,
            'confirmation_rate' => round((float) $row->confirmation_rate, 1),
            'delivery_rate' => round((float) $row->delivery_rate, 1),
            'return_rate' => round((float) $row->return_rate, 1),
            'first_order_at' => $row->first_at ? Carbon::parse($row->first_at)->toIso8601String() : null,
            'last_order_at' => $row->last_at ? Carbon::parse($row->last_at)->toIso8601String() : null,
            'segments' => $this->segmentsFor($row),
            'blocked' => $block ? $block->toPayload() : null,
        ];
    }

    /** Segment keys matching an aggregate row (evaluated in PHP for display). */
    public function segmentsFor(object $row): array
    {
        $out = [];
        foreach (config('client_segments.segments') as $key => $def) {
            $ok = true;
            foreach ($def['rules'] as $metric => $b) {
                if ($metric === 'days_since_first' || $metric === 'days_since_last') {
                    $at = $metric === 'days_since_first' ? $row->first_at : $row->last_at;
                    $days = $at ? Carbon::parse($at)->diffInDays(now()) : PHP_INT_MAX;
                    $v = $days;
                } else {
                    $v = (float) $row->{$metric};
                }
                if ((isset($b['min']) && $v < $b['min']) || (isset($b['max']) && $v > $b['max'])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** Distinct shipping addresses used by the client (newest first). */
    public function addresses(string $key): array
    {
        return Order::query()->where('phone_key', $key)->latest('id')->get(['shipping_address', 'created_at'])
            ->map(function ($o) {
                $a = $o->shipping_address ?: [];
                $line = trim(implode(', ', array_filter([$a['address1'] ?? null, $a['address2'] ?? null, $a['city'] ?? null, $a['zip'] ?? null])));

                return $line;
            })->filter()->unique()->values()->all();
    }
}
