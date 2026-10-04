<?php

namespace App\Services\Sift;

use RuntimeException;

/** Sift.ma error. The message is always redacted (no API key) and in French when ours. */
class SiftException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $endpoint = '',
        public readonly ?int $httpStatus = null,
        public readonly mixed $response = null,
        public readonly bool $configuration = false,
        public readonly string $method = '',
    ) {
        parent::__construct($message);
    }
}
