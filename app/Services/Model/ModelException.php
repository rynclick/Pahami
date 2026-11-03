<?php

namespace App\Services\Model;

use RuntimeException;

/**
 * Thrown when a model call fails after retries, or returns no usable content.
 */
class ModelException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $provider = null,
    ) {
        parent::__construct($message);
    }
}
