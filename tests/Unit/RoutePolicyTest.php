<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Context\RoutePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoutePolicy::class)]
final class RoutePolicyTest extends TestCase
{
    public function testListedPathsGetTheirRelaxation(): void
    {
        $policy = $this->policy();

        self::assertSame(RoutePolicy::PUBLIC, $policy->for('/api/v1/health'));
        self::assertSame(RoutePolicy::IDENTITY_ONLY, $policy->for('/api/v1/products'));
        self::assertSame(RoutePolicy::IDENTITY_ONLY, $policy->for('/api/v1/products/abc/features'));
    }

    /**
     * The default. A route nobody listed is fully protected, so forgetting to
     * classify one fails safe.
     *
     * @param non-empty-string $path
     */
    #[DataProvider('unlistedPaths')]
    public function testAnythingElseRequiresTheFullChain(string $path): void
    {
        self::assertSame(RoutePolicy::FULL, $this->policy()->for($path));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function unlistedPaths(): iterable
    {
        yield 'tenant route' => ['/api/v1/tenants/current'];
        yield 'me' => ['/api/v1/me'];
        yield 'unknown' => ['/api/v1/something-new'];
    }

    /**
     * Prefix matching must respect segment boundaries, or a path that merely
     * starts with the same letters would inherit the relaxation.
     *
     * @param non-empty-string $path
     */
    #[DataProvider('lookalikePaths')]
    public function testLookalikePathsDoNotInheritTheRelaxation(string $path): void
    {
        self::assertSame(RoutePolicy::FULL, $this->policy()->for($path));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function lookalikePaths(): iterable
    {
        yield 'longer segment' => ['/api/v1/productsomething'];
        yield 'different resource' => ['/api/v1/products-admin'];
        yield 'health lookalike' => ['/api/v1/healthz'];
    }

    private function policy(): RoutePolicy
    {
        return new RoutePolicy(['/api/v1/health'], ['/api/v1/products']);
    }
}
