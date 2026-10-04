<?php

namespace App\Services\Ozon;

use RuntimeException;

/** Ozon Express error. The message is always redacted (no API key) and in French when ours. */
class OzonException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $endpoint = '',
        public readonly ?int $httpStatus = null,
        public readonly mixed $response = null,
        public readonly bool $configuration = false,
    ) {
        parent::__construct($message);
    }
}
