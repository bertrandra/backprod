<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

final class MethodNotAllowedException extends HttpException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(array $allowed)
    {
        parent::__construct(
            405,
            'METHOD_NOT_ALLOWED',
            'This method is not allowed for the requested resource.',
            ['allowed' => $allowed],
        );
    }
}
