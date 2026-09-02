<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

final class BadRequestException extends HttpException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $code, string $message, array $details = [])
    {
        parent::__construct(400, $code, $message, $details);
    }
}
