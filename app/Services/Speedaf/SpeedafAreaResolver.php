<?php

namespace App\Services\Speedaf;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orders only carry a city; Speedaf also asks for province (Moroccan region, e.g.
 * "Casablanca - Settat") and district. The region tree (§6.2) is fetched once and cached;
 * when a city is unknown (or the API is unreachable) the city name is used for all three levels.
 */
class SpeedafAreaResolver
{
    public const CACHE_TTL = 604800; // 7 days

    public function __construct(protected SpeedafClient $client) {}

    /** @return array{province: string, city: string, district: string, matched: bool} */
    public function resolve(?string $city, ?string $district = null): array
    {
        $city = trim((string) $city);
        $fallback = ['province' => $city, 'city' => $city, 'district' => trim((string) $district) ?: $city, 'matched' => false];
        if ($city === '') {
            return $fallback;
        }

        $index = $this->cityIndex();
        $key = self::normalize($city);
        $hit = $index[$key] ?? null;
        if (! $hit) {
            foreach ($index as $name => $row) {
                if ($name !== '' && (str_starts_with($name, $key.' ') || str_starts_with($key, $name.' '))) {
                    $hit = $row;
                    break;
                }
            }
        }
        if (! $hit) {
            return $fallback;
        }

        $districtName = $fallback['district'];
        if ($district) {
            foreach ($hit['districts'] as $d) {
                if (self::normalize($d) === self::normalize($district)) {
                    $districtName = $d;
                }
            }
        } elseif (count($hit['districts']) === 1) {
            $districtName = $hit['districts'][0];
        }

        return ['province' => $hit['province'], 'city' => $hit['city'], 'district' => $districtName ?: $hit['city'], 'matched' => true];
    }

    /** normalized city name => [province, city, districts[]] */
    public function cityIndex(): array
    {
        $settings = $this->client->settings();
        $country = strtoupper($settings->country_code ?: 'MA');
        $cacheKey = 'speedaf:area-index:'.$settings->environment.':'.$country;

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $tree = $this->client->areaTree($country);
        } catch (Throwable $e) {
            Log::info('Speedaf area tree unavailable', ['error' => $e->getMessage()]);

            return [];
        }

        $index = self::buildIndex($tree);
        if ($index !== []) {
            Cache::put($cacheKey, $index, self::CACHE_TTL);
        }

        return $index;
    }

    /** Accepts the country node itself or a list of country nodes. */
    public static function buildIndex(array $tree): array
    {
        $countries = array_is_list($tree) ? $tree : [$tree];
        $index = [];
        foreach ($countries as $country) {
            foreach ((array) ($country['children'] ?? []) as $province) {
                foreach ((array) ($province['children'] ?? []) as $city) {
                    $name = trim((string) ($city['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $key = self::normalize($name);
                    $districts = array_values(array_filter(array_map(fn ($d) => trim((string) ($d['name'] ?? '')), (array) ($city['children'] ?? []))));
                    // Keep the entry with the most districts when a city name appears twice.
                    if (! isset($index[$key]) || count($districts) > count($index[$key]['districts'])) {
                        $index[$key] = ['province' => (string) ($province['name'] ?? $name), 'city' => $name, 'districts' => $districts];
                    }
                }
            }
        }

        return $index;
    }

    public static function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
