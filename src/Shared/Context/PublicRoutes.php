<?php

declare(strict_types=1);

namespace App\Shared\Context;

/**
 * The paths that may be reached without a request context.
 *
 * Default-deny: anything not listed here requires the full §10.6 chain. A new
 * route is therefore protected by omission rather than by remembering to
 * protect it, which is the failure mode this list exists to prevent.
 */
final class PublicRoutes
{
    /**
     * @param list<string> $paths exact, already-decoded request paths
     */
    public function __construct(private readonly array $paths)
    {
    }

    public function includes(string $path): bool
    {
        return in_array($path, $this->paths, true);
    }
}
