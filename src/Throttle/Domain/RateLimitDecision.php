<?php

declare(strict_types=1);

namespace App\Throttle\Domain;

/**
 * What one request cost, and whether it was the one too many.
 *
 * The hit is always counted, including the one that breaks the limit. A
 * limiter that stopped counting once it started refusing could not tell a
 * caller who backed off from one still hammering the door, and the second is
 * the one worth knowing about.
 */
final class RateLimitDecision
{
    public function __construct(
        public readonly int $hits,
        public readonly int $limit,
        public readonly int $windowSeconds,
        public readonly int $retryAfterSeconds,
    ) {
    }

    public function allowed(): bool
    {
        return $this->hits <= $this->limit;
    }

    /**
     * How many more this window will take. Never negative: a caller past the
     * limit has none left, and saying "minus four" invites arithmetic
     * somebody will get wrong.
     */
    public function remaining(): int
    {
        return max(0, $this->limit - $this->hits);
    }
}
