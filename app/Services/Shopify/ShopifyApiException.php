<?php

namespace App\Services\Shopify;

use RuntimeException;

/** Shopify Admin API failure. Retryable = 429, 5xx, network, or GraphQL THROTTLED. */
class ShopifyApiException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $userErrors
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
        public readonly array $userErrors = [],
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(int $status, string $body, ?string $retryAfterHeader = null): self
    {
        $retryable = $status === 429 || $status >= 500;
        $retryAfter = null;
        if ($retryAfterHeader !== null && $retryAfterHeader !== '' && is_numeric($retryAfterHeader)) {
            $retryAfter = max(1, (int) $retryAfterHeader);
        }

        return new self(
            "Shopify API error ({$status}): ".mb_substr($body, 0, 500),
            $status,
            $retryable,
            $retryAfter,
        );
    }
}
