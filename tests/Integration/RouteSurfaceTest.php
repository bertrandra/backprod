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
        $relaxed = array_merge(self::PUBLIC_PATHS, self::IDENTITY_ONLY_PATHS);
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
