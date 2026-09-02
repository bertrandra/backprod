<?php

declare(strict_types=1);

namespace App\Shared\Context;

/**
 * How much context a path requires before a handler may run.
 *
 * Three levels, because product discovery does not fit the other two:
 *
 *   PUBLIC          nothing — the liveness probe
 *   IDENTITY_ONLY   authenticated, but no product or tenant yet
 *   FULL            the whole §10.6 chain
 *
 * IDENTITY_ONLY exists for a real reason. A client cannot send X-Product
 * until it knows which products it may use, and it learns that from
 * /products — so requiring product context there would make discovery
 * depend on its own result. Those endpoints authorise per product instead,
 * from membership.
 *
 * Default-deny: a path matching nothing here gets FULL, so a new route is
 * fully protected unless someone deliberately relaxes it.
 */
final class RoutePolicy
{
    public const PUBLIC = 'public';
    public const IDENTITY_ONLY = 'identity';
    public const FULL = 'full';

    /**
     * @param list<string> $publicPaths       exact, already-decoded paths
     * @param list<string> $identityOnlyPaths path prefixes
     */
    public function __construct(
        private readonly array $publicPaths,
        private readonly array $identityOnlyPaths,
    ) {
    }

    /**
     * @return self::PUBLIC|self::IDENTITY_ONLY|self::FULL
     */
    public function for(string $path): string
    {
        if (in_array($path, $this->publicPaths, true)) {
            return self::PUBLIC;
        }

        foreach ($this->identityOnlyPaths as $prefix) {
            // Prefix matching, because these paths carry ids: a boundary check
            // stops /api/v1/productsomething from inheriting the relaxation.
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return self::IDENTITY_ONLY;
            }
        }

        return self::FULL;
    }
}
