<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Cache;

/**
 * Expected echo of an outbound push: valid 10 minutes. A matching webhook is
 * logged as echo and must not push again or re-fire automations for those fields.
 */
class SyncEcho
{
    public static function remember(int $shopId, string $entityType, string $shopifyId, array $fields, ?string $updatedAt = null): void
    {
        Cache::put(self::key($shopId, $entityType, $shopifyId), [
            'hash' => self::hash($fields),
            'fields' => array_keys($fields),
            'updated_at' => $updatedAt,
        ], now()->addMinutes(10));
    }

    /** @return array{hash:string, fields:list<string>, updated_at:?string}|null */
    public static function find(int $shopId, string $entityType, string $shopifyId): ?array
    {
        $row = Cache::get(self::key($shopId, $entityType, $shopifyId));

        return is_array($row) ? $row : null;
    }

    /** @return list<string>|null remembered field names when the incoming subset matches; the echo is then consumed */
    public static function matchingFields(int $shopId, string $entityType, string $shopifyId, array $incoming): ?array
    {
        $row = self::find($shopId, $entityType, $shopifyId);
        if (! $row) {
            return null;
        }
        $subset = [];
        foreach ($row['fields'] as $field) {
            $subset[$field] = $incoming[$field] ?? null;
        }

        if (! hash_equals($row['hash'], self::hash($subset))) {
            return null;
        }
        Cache::forget(self::key($shopId, $entityType, $shopifyId));

        return $row['fields'];
    }

    public static function matches(int $shopId, string $entityType, string $shopifyId, array $incoming): bool
    {
        return self::matchingFields($shopId, $entityType, $shopifyId, $incoming) !== null;
    }

    /**
     * Stable line snapshot for the order echo hash: id, variant, quantity, price.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{id: ?int, variant_id: ?int, quantity: int, price: string}>
     */
    public static function lineFingerprint(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $out[] = [
                'id' => isset($line['id']) && $line['id'] !== '' ? (int) $line['id'] : null,
                'variant_id' => isset($line['variant_id']) && $line['variant_id'] !== '' ? (int) $line['variant_id'] : null,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'price' => number_format((float) ($line['price'] ?? 0), 2, '.', ''),
            ];
        }

        return $out;
    }

    public static function hash(array $fields): string
    {
        ksort($fields);
        $normalized = [];
        foreach ($fields as $key => $value) {
            $normalized[$key] = is_scalar($value) || $value === null
                ? $value
                : json_decode(json_encode($value), true);
        }

        return hash('sha256', json_encode($normalized));
    }

    private static function key(int $shopId, string $entityType, string $shopifyId): string
    {
        return "shopify-echo:{$shopId}:{$entityType}:{$shopifyId}";
    }
}
