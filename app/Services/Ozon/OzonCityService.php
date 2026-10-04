<?php

namespace App\Services\Ozon;

use App\Models\Order;
use App\Models\OzonCity;
use App\Models\OzonCityMapping;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ozon city list + mapping « ville de la commande » → Ozon city ID. The city is never sent as
 * text: an order whose city has no mapping cannot be sent until it is associated (auto-match
 * on the official names, manual override in Paramètres → Transporteurs → Ozon → Mapping villes).
 */
class OzonCityService
{
    /** Common spellings of order cities => official Ozon name. */
    public const ALIASES = [
        'tangier' => 'tanger', 'tanja' => 'tanger', 'fez' => 'fes', 'marrakesh' => 'marrakech', 'meknas' => 'meknes',
        'sale' => 'sale', 'sla' => 'sale', 'kenitra' => 'kenitra ville', 'el jadida' => 'el jadida', 'eljadida' => 'el jadida',
        'beni mellal' => 'beni mellal', 'benimellal' => 'beni mellal', 'settat' => 'settat', 'mohammedia' => 'mohammedia',
    ];

    public static function key(?string $city): string
    {
        $k = Str::lower(Str::ascii(trim((string) $city)));
        $k = str_replace(['–', '—', '_'], '-', $k);
        $k = preg_replace('/[^a-z0-9\- ]+/', ' ', $k);
        $k = preg_replace('/\s*-\s*/', ' - ', $k);

        return trim(preg_replace('/\s+/', ' ', $k));
    }

    /** GET /cities → ozon_cities (upsert, cities that disappeared are deactivated). */
    public function sync(?OzonClient $client = null): array
    {
        $rows = ($client ?? new OzonClient)->cities();
        $now = now();
        DB::transaction(function () use ($rows, $now) {
            $ids = [];
            foreach (array_chunk($rows, 200) as $chunk) {
                OzonCity::query()->upsert(array_map(fn ($r) => [
                    'ozon_id' => $r['id'], 'ref' => $r['ref'], 'name' => trim(preg_replace('/\s+/u', ' ', $r['name'])),
                    'name_key' => self::key($r['name']), 'delivered_price' => $r['delivered_price'], 'returned_price' => $r['returned_price'],
                    'refused_price' => $r['refused_price'], 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk), ['ozon_id'], ['ref', 'name', 'name_key', 'delivered_price', 'returned_price', 'refused_price', 'active', 'updated_at']);
                $ids = array_merge($ids, array_column($chunk, 'id'));
            }
            OzonCity::query()->whereNotIn('ozon_id', $ids)->update(['active' => false]);
        });

        return ['count' => count($rows)];
    }

    public function hasCities(): bool
    {
        return OzonCity::query()->exists();
    }

    /** Best official city for a free-text city, or null (exact name, alias, "X ville"). */
    public function autoMatch(?string $city): ?OzonCity
    {
        $key = self::key($city);
        if ($key === '') {
            return null;
        }
        $candidates = array_unique(array_filter([
            $key,
            self::ALIASES[$key] ?? null,
            preg_replace('/ ville$/', '', $key),
            $key.' ville',
        ]));
        foreach ($candidates as $c) {
            $found = OzonCity::query()->where('active', true)->where('name_key', $c)->orderBy('ozon_id')->get();
            if ($found->count() === 1) {
                return $found->first();
            }
        }

        return null;
    }

    /** A few official cities whose name contains the order city (help for the manual choice). */
    public function suggestions(?string $city, int $limit = 5): array
    {
        $key = self::key($city);
        if (mb_strlen($key) < 3) {
            return [];
        }

        return OzonCity::query()->where('active', true)
            ->where(fn ($q) => $q->where('name_key', 'like', $key.'%')->orWhere('name_key', 'like', '% '.$key.'%'))
            ->orderByRaw('length(name_key)')->limit($limit)->get()->map->toOption()->all();
    }

    /**
     * Ozon city for an order city. Uses the saved mapping; otherwise tries the auto-match and
     * remembers the result (also when nothing matched, so the city shows up as "à associer").
     */
    public function resolve(int $companyId, ?string $city): ?OzonCity
    {
        $key = self::key($city);
        if ($key === '') {
            return null;
        }
        $mapping = OzonCityMapping::query()->where('company_id', $companyId)->where('city_key', $key)->first();
        if ($mapping && $mapping->ozon_city_id) {
            return OzonCity::query()->where('ozon_id', $mapping->ozon_city_id)->first();
        }
        if ($mapping && $mapping->source === 'manual') {
            return null;
        }
        $match = $this->autoMatch($city);
        OzonCityMapping::query()->updateOrCreate(
            ['company_id' => $companyId, 'city_key' => $key],
            ['city_label' => Str::limit(trim((string) $city), 160, ''), 'ozon_city_id' => $match?->ozon_id, 'source' => 'auto']
        );

        return $match;
    }

    /** Manual association (null = remove). */
    public function setMapping(int $companyId, string $city, ?int $ozonCityId, ?User $user = null): OzonCityMapping
    {
        $key = self::key($city);

        return OzonCityMapping::query()->updateOrCreate(
            ['company_id' => $companyId, 'city_key' => $key],
            ['city_label' => Str::limit(trim($city), 160, ''), 'ozon_city_id' => $ozonCityId, 'source' => $ozonCityId ? 'manual' : 'auto', 'updated_by' => $user?->id]
        );
    }

    /** Registers every distinct order city and auto-matches the new / unmatched ones. */
    public function autoMatchOrderCities(int $companyId): array
    {
        $stats = ['cities' => 0, 'matched' => 0, 'unmatched' => 0];
        foreach ($this->orderCities() as $label => $count) {
            $stats['cities']++;
            $existing = OzonCityMapping::query()->where('company_id', $companyId)->where('city_key', self::key($label))->first();
            if ($existing && ($existing->source === 'manual' || $existing->ozon_city_id)) {
                $stats['matched'] += $existing->ozon_city_id ? 1 : 0;
                $stats['unmatched'] += $existing->ozon_city_id ? 0 : 1;

                continue;
            }
            $this->resolve($companyId, $label) ? $stats['matched']++ : $stats['unmatched']++;
        }

        return $stats;
    }

    /** Distinct shipping cities of the orders => order count (first spelling wins). */
    public function orderCities(): array
    {
        $out = [];
        Order::query()->select(['id', 'shipping_address'])->chunkById(1000, function ($orders) use (&$out) {
            foreach ($orders as $o) {
                $city = trim((string) $o->shippingCity());
                if ($city === '') {
                    continue;
                }
                $key = self::key($city);
                $out[$key] ??= ['label' => $city, 'count' => 0];
                $out[$key]['count']++;
            }
        });
        $flat = [];
        foreach ($out as $row) {
            $flat[$row['label']] = $row['count'];
        }
        arsort($flat);

        return $flat;
    }
}
