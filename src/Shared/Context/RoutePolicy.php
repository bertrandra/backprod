<?php

declare(strict_types=1);

namespace App\Shared\Context;

/**
 * How much context a path requires before a handler may run.
 *
 * Four levels, because neither product discovery nor platform staff fits the
 * ordinary chain:
 *
 *   PUBLIC          nothing — the liveness probe
 *   IDENTITY_ONLY   authenticated, but no product or tenant yet
 *   STAFF           authenticated, with a platform role (§12.2)
 *   PRODUCT         a product key, no person (ADR-051 §4)
 *   FULL            the whole §10.6 chain
 *
 * IDENTITY_ONLY exists for a real reason. A client cannot send X-Product
 * until it knows which products it may use, and it learns that from
 * /products — so requiring product context there would make discovery
 * depend on its own result. Those endpoints authorise per product instead,
 * from membership.
 *
 * STAFF exists because a staff route cannot resolve a tenant the way every
 * other protected route does: there is no membership to derive one from —
 * that is the entire point of the identity. So the chain stops after
 * authentication and resolves a platform role instead, and the tenant
 * arrives as an explicit parameter the handler must justify and audit.
 *
 * The relaxation is only apparent. A STAFF route is not a FULL route with a
 * step skipped: it demands a platform role that no tenant membership can
 * grant, and refuses everyone else outright.
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
    public const STAFF = 'staff';
    public const PRODUCT = 'product';
    public const FULL = 'full';

    /**
     * @param list<string> $publicPaths      exact, already-decoded paths
     * @param list<string> $identityOnlyPaths path prefixes
     * @param list<string> $publicPrefixes   path prefixes reachable with no
     *                                       credential at all
     * @param list<string> $staffPrefixes    path prefixes requiring a platform
     *                                       role instead of a membership
     * @param list<string> $productPrefixes  path prefixes a product key calls
     *                                       (ADR-051 §4): no person, no
     *                                       session, the product from the key
     */
    public function __construct(
        private readonly array $publicPaths,
        private readonly array $identityOnlyPaths,
        private readonly array $publicPrefixes = [],
        private readonly array $staffPrefixes = [],
        private readonly array $productPrefixes = [],
    ) {
    }

    /**
     * @return self::PUBLIC|self::IDENTITY_ONLY|self::STAFF|self::PRODUCT|self::FULL
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

        // A product key's routes: a fifth level, not a relaxation of any
        // other — the caller is authenticated by a credential no person
        // holds, and the product is derived from it (ADR-051 §4).
        foreach ($this->productPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return self::PRODUCT;
            }
        }

        // Before the identity-only check, so a staff prefix nested under a
        // relaxed one could never inherit the relaxation and lose its role
        // requirement.
        foreach ($this->staffPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return self::STAFF;
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
