<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * No usable credential was presented, or it did not verify.
 *
 * The message is deliberately uniform: telling a caller *why* verification
 * failed (bad signature vs. expired vs. wrong audience) is an oracle for
 * probing tokens. The specific reason is logged, not returned.
 */
final class UnauthenticatedException extends HttpException
{
    public function __construct()
    {
        parent::__construct(401, 'UNAUTHENTICATED', 'Authentication is required.');
    }
}
