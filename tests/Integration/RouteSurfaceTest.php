<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Context\RoutePolicy;
use App\Tests\Support\RecordingRouteCollector;
use FastRoute\RouteCollector;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;

/**
 * What the platform actually exposes, and how much of the chain each route
 * has to pass (R5).
 *
 * `RoutePolicyTest` proves the policy *class* behaves — including that a path
 * nobody listed still gets FULL. What it cannot prove is anything about the
 * policy the application is configured with, because it builds its own. So
 * `publicPrefixes: ['/api/v1/webhooks', '/api/v1/downloads', '/api/v1/invoices']`
 * would have shipped green.
 *
 * This closes that: every route the application registers is classified by
 * the policy the application is wired with, and everything relaxed below FULL
 * is named here. Adding a route under a public prefix fails until somebody
 * writes it down — which is the point. A relaxation should cost a deliberate
 * act, not an oversight.
 *
 * R5 was "product context added late", and it did not happen: M1 shipped
 * first and the policy defaults to FULL. This is what keeps it from happening
 * backwards.
 */
#[CoversNothing]
final class RouteSurfaceTest extends ApiTestCase
{
    /**
     * Reachable with no credential at all. Each of these authenticates the
     * *request* — a provider's signature, our own signed link — because there
     * is no caller to authenticate.
     */
    private const PUBLIC_PATHS = [
        'GET /api/v1/health',
        // U12. Each authenticates the *request* rather than the caller, which is
        // what this list requires of anything on it: `token` verifies a password,
        // and `refresh` and `sign-out` verify a rotating `HttpOnly` cookie. They
        // are exact paths rather than a prefix, so nothing added under /auth later
        // inherits being public by accident.
        'POST /api/v1/auth/token',
        'POST /api/v1/auth/refresh',
        'POST /api/v1/auth/sign-out',
        // ADR-041. The two that break this list's usual pattern, and they are
        // named here precisely so that is a deliberate act.
        //
        // `sign-up` authenticates nothing — there is nobody to authenticate
        // yet, which is what a storefront selling to strangers means. What
        // stands in for it is §31's public rate limit and the fact that the
        // only thing it can create is an ordinary customer: no platform role,
        // no offer authoring (ADR-040's flag stays false), one tenant.
        //
        // `verify-email` authenticates the *token in the link*, which is the
        // usual pattern after all — 256 bits of CSPRNG, stored as a SHA-256,
        // single-use and expiring. It issues no session, so following the link
        // never turns a mail into a credential.
        'POST /api/v1/auth/sign-up',
        'POST /api/v1/auth/verify-email',
        // The forgotten password (2026-09-19): an address, always answered
        // 202; and the single-use link that sets a new one, which signs
        // nobody in and revokes every session it finds.
        'POST /api/v1/auth/password/forgot',
        'POST /api/v1/auth/password/reset',
        // The public keys sessions are signed with (ADR-051 milestone E): a
        // public key is public, and a product verifying bearers reads it.
        'GET /api/v1/auth/jwks',
        // The shop window (ADR-041). These authenticate nothing either, and
        // they do not need to: what they return is only what somebody
        // explicitly marked `publicly_listed` and that is inside its sale
        // window, filtered in SQL rather than after the fetch — so a request
        // with no session never pulls a private price out of storage.
        'GET /api/v1/public/offers',
        'GET /api/v1/public/offers/{offerId}',
        'GET /api/v1/public/products',
        'GET /api/v1/public/tenant',
        // The demonstration page: a 404 until switched on, so the route itself
        // reveals nothing.
        'GET /api/v1/public/demo',
        'POST /api/v1/webhooks/payments/{provider}',
        'POST /api/v1/webhooks/einvoice/{provider}',
        'GET /api/v1/downloads/{assetId}/content',
    ];

    /**
     * Authenticated, but with no product resolved — because the client cannot
     * name a product until it has learned which ones it may use.
     */
    private const IDENTITY_ONLY_PATHS = [
        'GET /api/v1/products',
        'GET /api/v1/products/{productId}',
        'GET /api/v1/products/{productId}/catalog',
        'GET /api/v1/products/{productId}/features',
        'GET /api/v1/products/{productId}/configuration',
    ];

    /** A product key's routes (ADR-051 §4): no session, the product from the key. */
    private const PRODUCT_PATHS = [
        'GET /api/v1/product/tenants/{tenantId}/entitlements',
        'POST /api/v1/product/tenants/{tenantId}/usage',
        'GET /api/v1/product/tenants/{tenantId}/members',
        // The one that names no tenant (2026-09-24): a product saying what
        // it gates on is saying something about itself.
        'PUT /api/v1/product/capabilities',
    ];

    public function testEveryProductKeyRouteIsOneWeMeantToOpenToAKey(): void
    {
        self::assertSame(
            self::PRODUCT_PATHS,
            $this->routesClassified(RoutePolicy::PRODUCT),
            'A route answers to a product key rather than a person. If that is intended, name it above.',
        );
    }

    public function testEveryPublicRouteIsOneWeMeantToPublish(): void
    {
        self::assertSame(
            self::PUBLIC_PATHS,
            $this->routesClassified(RoutePolicy::PUBLIC),
            'A route is reachable with no credential. If that is intended, name it above'
            . ' — and make sure it authenticates the request itself.',
        );
    }

    public function testEveryIdentityOnlyRouteIsOneWeMeantToRelax(): void
    {
        self::assertSame(
            self::IDENTITY_ONLY_PATHS,
            $this->routesClassified(RoutePolicy::IDENTITY_ONLY),
            'A route runs without a resolved product. Discovery needs that; a resource does not.',
        );
    }

    /**
     * The rest divide into staff surfaces and the full chain, and no route
     * escapes classification entirely.
     */
    public function testEveryOtherRouteIsStaffOrFullyProtected(): void
    {
        $relaxed = array_merge(self::PUBLIC_PATHS, self::IDENTITY_ONLY_PATHS, self::PRODUCT_PATHS);
        $unclassified = [];

        foreach ($this->registeredRoutes() as $route) {
            $name = $route['method'] . ' ' . $route['path'];
            $level = $this->policy()->for(self::concrete($route['path']));

            if (in_array($name, $relaxed, true)) {
                continue;
            }

            if ($level !== RoutePolicy::STAFF && $level !== RoutePolicy::FULL) {
                $unclassified[] = $name . ' → ' . $level;
            }
        }

        self::assertSame([], $unclassified);
    }

    /**
     * Not a tautology about the class — a fact about this application: it has
     * routes, and most of them are behind the whole chain.
     */
    public function testMostOfTheSurfaceIsBehindTheFullChain(): void
    {
        $routes = $this->registeredRoutes();

        // 114 today. The bound is loose on purpose: this is not a count
        // to keep updated, it is a guard against the collector silently
        // recording nothing and every assertion above passing on an
        // empty list.
        self::assertGreaterThan(50, count($routes));
        self::assertGreaterThan(
            count($routes) / 2,
            count($this->routesClassified(RoutePolicy::FULL)),
        );
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * @return list<string> "METHOD /path", in registration order
     */
    private function routesClassified(string $level): array
    {
        $matching = [];

        foreach ($this->registeredRoutes() as $route) {
            if ($this->policy()->for(self::concrete($route['path'])) === $level) {
                $matching[] = $route['method'] . ' ' . $route['path'];
            }
        }

        return $matching;
    }

    /**
     * @return list<array{method: string, path: string}>
     */
    private function registeredRoutes(): array
    {
        $factory = require dirname(__DIR__, 2) . '/config/routes.php';

        if (!is_callable($factory)) {
            throw new RuntimeException('config/routes.php must return a callable.');
        }

        $collector = new RecordingRouteCollector();
        $factory($collector);

        self::assertInstanceOf(RouteCollector::class, $collector);

        return $collector->routes();
    }

    private function policy(): RoutePolicy
    {
        $policy = $this->container()->get(RoutePolicy::class);

        self::assertInstanceOf(RoutePolicy::class, $policy);

        return $policy;
    }

    /**
     * A placeholder stands for a real segment, and the policy matches on
     * prefixes — so `{id}` has to become something rather than nothing, or
     * `/api/v1/products/{id}/features` would be tested as a path no client
     * ever sends.
     */
    private static function concrete(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', 'x', $path);
    }
}
