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
 *
 * PUBLIC comes in two shapes. Exact paths are the safe kind — a fixed string
 * cannot accidentally cover a route added later. Prefixes exist because a
 * webhook carries its sender in the URL, and they are the most dangerous
 * entry a route can have: everything under one is reachable by anybody on
 * the internet. A handler behind a public prefix must therefore authenticate
 * the *request* itself — for the payment webhook, by verifying the
 * provider's signature over the raw body before reading a single field.
 * Nothing may be mounted under one that does not.
 */
final class RoutePolicy
{
    public const PUBLIC = 'public';
    public const IDENTITY_ONLY = 'identity';
    public const FULL = 'full';

    /**
     * @param list<string> $publicPaths      exact, already-decoded paths
     * @param list<string> $identityOnlyPaths path prefixes
     * @param list<string> $publicPrefixes   path prefixes reachable with no
     *                                       credential at all
     */
    public function __construct(
        private readonly array $publicPaths,
        private readonly array $identityOnlyPaths,
        private readonly array $publicPrefixes = [],
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

        foreach ($this->publicPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return self::PUBLIC;
            }
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
