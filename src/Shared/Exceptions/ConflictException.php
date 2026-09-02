<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

final class ConflictException extends HttpException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $code, string $message, array $details = [])
    {
        parent::__construct(409, $code, $message, $details);
    }
}
