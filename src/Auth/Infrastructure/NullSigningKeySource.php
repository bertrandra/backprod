<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

/**
 * An empty key set, used when no signing keys are configured.
 *
 * Every token then fails verification and the caller gets 401. That is the
 * safe direction: an unconfigured deployment authenticates nobody, rather
 * than failing open or refusing to boot and taking the liveness probe down
 * with it.
 */
final class NullSigningKeySource implements SigningKeySource
{
    public function keys(): array
    {
        return [];
    }
}
