<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * The request body is larger than the platform will store inline.
 *
 * The limit and the actual size are both returned: a client that cannot see
 * how far over it is has to bisect its own document to find out.
 */
final class PayloadTooLargeException extends HttpException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct(413, 'PAYLOAD_TOO_LARGE', $message, $details);
    }
}
