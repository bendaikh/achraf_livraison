<?php

namespace App\Jobs;

use App\Services\Shopify\ShopifyApiException;

/** Shared retry policy for every Shopify queue job. */
trait HandlesShopifyRetries
{
    public int $tries = 6;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    protected function releaseForShopify(ShopifyApiException $e): bool
    {
        if (! $e->retryable || $this->attempts() >= $this->tries) {
            return false;
        }
        $fallback = $this->backoff()[max(0, $this->attempts() - 1)] ?? 3600;
        $this->release($e->retryAfter ?: $fallback);

        return true;
    }
}
