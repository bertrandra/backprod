<?php

declare(strict_types=1);

use App\Auth\Domain\AuthProvider;
use App\Auth\Infrastructure\NullSigningKeySource;
use App\Auth\Infrastructure\SigningKeySource;
use App\Auth\Infrastructure\StaticSigningKeySource;
use App\Auth\Infrastructure\SupabaseJwtAuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Infrastructure\InMemoryEntitlementRepository;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\PostgresProductRepository;
use App\Shared\Context\PublicRoutes;
use App\Shared\Context\RequestContextMiddleware;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Http\Middleware\ErrorHandlerMiddleware;
use App\Shared\Http\Middleware\RequestIdMiddleware;
use App\Shared\Http\MiddlewarePipeline;
use App\Shared\Http\Router;
use App\Shared\Logging\ErrorLogLogger;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\PostgresTenantMembershipRepository;
use App\User\Domain\UserDirectory;
use App\User\Infrastructure\PostgresUserDirectory;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;
use function FastRoute\simpleDispatcher;

/**
 * Container definitions.
 *
 * Middleware order is the security boundary and is declared here, once:
 * RequestId first so every failure downstream carries a correlation id, then
 * the error handler, then the §10.6 context chain, then routing. A route
 * cannot opt out of the chain — only the public-routes list can exempt a
 * path, and it is deliberately tiny.
 *
 * @param array<string, mixed> $overrides definitions replacing the defaults,
 *                                        used by tests to supply doubles
 */
return static function (array $overrides = []): ContainerInterface {
    // Reads $_ENV first so a .env loaded immutably is visible, then falls back
    // to the real environment for container and CI deployments.
    $env = static function (string $key, string $default = ''): string {
        $value = $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    };

    $builder = new ContainerBuilder();

    $builder->addDefinitions([
        LoggerInterface::class => autowire(ErrorLogLogger::class),

        // --- Identity -------------------------------------------------------
        // Without configured keys this yields an empty key set, so every token
        // fails and the API authenticates nobody (see NullSigningKeySource).
        SigningKeySource::class => factory(static function () use ($env): SigningKeySource {
            $jwks = $env('SUPABASE_JWKS');

            return $jwks === '' ? new NullSigningKeySource() : StaticSigningKeySource::fromJson($jwks);
        }),

        AuthProvider::class => autowire(SupabaseJwtAuthProvider::class)
            ->constructorParameter('keys', get(SigningKeySource::class))
            ->constructorParameter('expectedIssuer', $env('SUPABASE_ISSUER'))
            ->constructorParameter('expectedAudience', $env('SUPABASE_AUDIENCE', 'authenticated')),

        // --- Persistence ----------------------------------------------------
        // DBAL connects lazily, so an unconfigured or unreachable database
        // does not stop the process from serving the liveness probe.
        Connection::class => factory(
            static fn (): Connection => ConnectionFactory::fromDsn($env('DATABASE_DSN')),
        ),

        // --- Platform data --------------------------------------------------
        UserDirectory::class => autowire(PostgresUserDirectory::class),
        ProductRepository::class => autowire(PostgresProductRepository::class),
        TenantMembershipRepository::class => autowire(PostgresTenantMembershipRepository::class),

        // Still in memory and seeded empty: offers and subscriptions are M5,
        // and until then no tenant may use anything. Absence of a
        // subscription must read as "may use nothing", never as "may use
        // everything".
        EntitlementRepository::class => factory(
            static fn (): EntitlementRepository => new InMemoryEntitlementRepository([]),
        ),

        // --- HTTP -----------------------------------------------------------
        PublicRoutes::class => factory(
            static fn (): PublicRoutes => new PublicRoutes(['/api/v1/health']),
        ),

        Dispatcher::class => factory(static function (): Dispatcher {
            $routes = require __DIR__ . '/routes.php';

            if (!is_callable($routes)) {
                throw new RuntimeException('config/routes.php must return a callable.');
            }

            return simpleDispatcher(static function (RouteCollector $collector) use ($routes): void {
                $routes($collector);
            });
        }),

        MiddlewarePipeline::class => autowire()
            ->constructorParameter('middleware', [
                get(RequestIdMiddleware::class),
                get(ErrorHandlerMiddleware::class),
                get(RequestContextMiddleware::class),
            ])
            ->constructorParameter('finalHandler', get(Router::class)),

        RequestHandlerInterface::class => get(MiddlewarePipeline::class),
    ]);

    $builder->addDefinitions($overrides);

    return $builder->build();
};
