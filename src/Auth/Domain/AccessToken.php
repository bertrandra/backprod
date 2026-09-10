<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use SensitiveParameter;

/**
 * A credential this platform issued, and how long it is good for.
 *
 * `expiresIn` is a *duration* rather than an instant, deliberately. The client
 * schedules its renewal against its own clock, and a browser whose clock is ten
 * minutes fast would treat an absolute timestamp as already past. A duration is
 * measured the same by a wrong clock as by a right one.
 */
final class AccessToken
{
    public function __construct(
        #[SensitiveParameter]
        public readonly string $token,
        public readonly int $expiresIn,
    ) {
    }
}
