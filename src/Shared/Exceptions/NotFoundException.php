<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

final class NotFoundException extends HttpException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'Resource not found.', array $details = [])
    {
        parent::__construct(404, 'NOT_FOUND', $message, $details);
    }
}
