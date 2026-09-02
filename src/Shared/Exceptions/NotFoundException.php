<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

final class NotFoundException extends HttpException
{
    /**
     * The error code is overridable so callers can be specific — an unknown
     * product and an unknown route are both 404 but are not the same event.
     *
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Resource not found.',
        array $details = [],
        string $errorCode = 'NOT_FOUND',
    ) {
        parent::__construct(404, $errorCode, $message, $details);
    }
}
