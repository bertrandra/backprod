<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * Base for every error that maps to a documented HTTP status.
 *
 * The $code carried here is the stable machine-readable code published in the
 * OpenAPI contract (Architecture V2 §10.4) — never a raw driver or framework
 * message, which must never reach a client (§31).
 */
abstract class HttpException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
