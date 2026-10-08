<?php

namespace App\Services\Shopify;

/**
 * Marks the current call stack as an inbound Shopify apply, and records which
 * order fields must not re-fire automations (echo of a Flow push).
 */
class SyncContext
{
    private static int $inboundDepth = 0;

    /** @var array<int, true|list<string>> */
    public static array $suppressOrderFields = [];

    public static function isInbound(): bool
    {
        return self::$inboundDepth > 0;
    }

    public static function inbound(callable $callback): mixed
    {
        self::$inboundDepth++;
        try {
            return $callback();
        } finally {
            self::$inboundDepth--;
        }
    }

    /** @param  true|list<string>  $fields  true = suppress every automation for this save */
    public static function suppressOrder(int $orderId, true|array $fields): void
    {
        self::$suppressOrderFields[$orderId] = $fields;
    }

    public static function suppressedFor(int $orderId): true|array|null
    {
        return self::$suppressOrderFields[$orderId] ?? null;
    }

    public static function clearSuppressed(int $orderId): void
    {
        unset(self::$suppressOrderFields[$orderId]);
    }
}
