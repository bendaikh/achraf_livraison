<?php

namespace App\Services\Catalog;

/**
 * Order line helpers. Lines live in orders.line_items (Shopify-compatible JSON). Each line gets a
 * stable "key": persisted once the lines are edited internally; before that it is derived from
 * the Shopify line id ("s<id>") or the position ("i<index>") — deterministic, so the key the UI
 * received is the one found when the edit arrives.
 */
class OrderLines
{
    /** @return list<array<string, mixed>> */
    public static function normalize(mixed $lines): array
    {
        $out = [];
        foreach (array_values(is_array($lines) ? $lines : []) as $i => $line) {
            if (! is_array($line)) {
                continue;
            }
            $line['key'] ??= ! empty($line['id']) ? 's'.$line['id'] : 'i'.$i;
            $line['quantity'] = max(0, (int) ($line['quantity'] ?? 1));
            $out[] = $line;
        }

        return $out;
    }

    public static function lineTotal(array $line): float
    {
        return round((float) ($line['price'] ?? 0) * (int) ($line['quantity'] ?? 1), 2);
    }

    public static function subtotal(array $lines): float
    {
        return round(array_sum(array_map(fn ($l) => self::lineTotal($l), $lines)), 2);
    }

    public static function label(array $line): string
    {
        $title = (string) ($line['title'] ?? $line['name'] ?? 'Produit');
        $variant = $line['variant_title'] ?? null;

        return $variant && $variant !== 'Default Title' ? "{$title} ({$variant})" : $title;
    }
}
