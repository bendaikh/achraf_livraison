<?php

namespace App\Services\Speedaf;

use RuntimeException;

/** Speedaf API error with a French, user-facing message. */
class SpeedafException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, public readonly ?array $response = null)
    {
        parent::__construct($message);
    }
}
