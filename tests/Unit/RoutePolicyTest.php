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

    #[DataProvider('staffPaths')]
    public function testStaffPathsRequireAPlatformRole(string $path): void
    {
        self::assertSame(RoutePolicy::STAFF, $this->staffAwarePolicy()->for($path));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function staffPaths(): iterable
    {
        yield 'the prefix itself' => ['/api/v1/staff'];
        yield 'a resource under it' => ['/api/v1/staff/tenants'];
        yield 'a nested id' => ['/api/v1/staff/tenants/8f0e6b3a-1111-2222-3333-444455556666'];
    }

    #[DataProvider('staffLookalikePaths')]
    public function testAStaffLookalikeGetsTheFullChain(string $path): void
    {
        // The boundary check matters more here than anywhere: a path that
        // merely starts with the same letters must not inherit a policy that
        // skips tenant resolution.
        self::assertSame(RoutePolicy::FULL, $this->staffAwarePolicy()->for($path));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function staffLookalikePaths(): iterable
    {
        yield 'longer segment' => ['/api/v1/staffing'];
        yield 'different resource' => ['/api/v1/staff-tools'];
    }

    public function testAStaffPrefixNestedUnderARelaxedOneKeepsItsRoleRequirement(): void
    {
        // Staff prefixes are matched before identity-only ones precisely so
        // this cannot come back STAFF-less: a relaxation must never swallow a
        // route that demands a platform role.
        $policy = new RoutePolicy(
            publicPaths: [],
            identityOnlyPaths: ['/api/v1/products'],
            publicPrefixes: [],
            staffPrefixes: ['/api/v1/products/staff'],
        );

        self::assertSame(RoutePolicy::STAFF, $policy->for('/api/v1/products/staff/audit'));
    }

    public function testAPathMatchingNothingIsStillFullyProtected(): void
    {
        self::assertSame(RoutePolicy::FULL, $this->staffAwarePolicy()->for('/api/v1/anything-new'));
    }

    private function policy(): RoutePolicy
    {
        return new RoutePolicy(['/api/v1/health'], ['/api/v1/products']);
    }

    private function staffAwarePolicy(): RoutePolicy
    {
        return new RoutePolicy(
            publicPaths: ['/api/v1/health'],
            identityOnlyPaths: ['/api/v1/products'],
            publicPrefixes: ['/api/v1/webhooks'],
            staffPrefixes: ['/api/v1/staff'],
        );
    }
}
