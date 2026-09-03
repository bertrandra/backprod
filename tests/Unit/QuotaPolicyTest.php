<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entitlement\Domain\Entitlement;
use App\Entitlement\Domain\QuotaPolicy;
use App\Entitlement\Domain\UsageMeter;
use App\Entitlement\Infrastructure\InMemoryEntitlementRepository;
use App\Shared\Exceptions\HttpException;
use App\Tests\Support\FixedUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Whether a tenant may consume one more of something.
 *
 * Three outcomes that are not interchangeable, because they are fixed in
 * three different places: buy it, upgrade it, or go ahead.
 */
#[CoversClass(QuotaPolicy::class)]
#[CoversClass(UsageMeter::class)]
final class QuotaPolicyTest extends TestCase
{
    private const TENANT = 'tenant-acme';
    private const PRODUCT = 'prod-atlas';
    private const FEATURE = 'max_projects';

    /**
     * Absence grants nothing. An offer that never mentioned the feature is
     * not a licence to use unlimited amounts of it.
     */
    public function testAFeatureTheTenantDoesNotHoldIsRefusedAsUnbought(): void
    {
        $error = self::refusal(self::policy([], 0));

        self::assertSame('ENTITLEMENT_REQUIRED', $error->errorCode());
    }

    public function testUsageBelowTheLimitIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        self::policy([self::quota(3)], 2)->assertMayConsume(self::TENANT, self::PRODUCT, self::FEATURE);
    }

    /**
     * The boundary the operator is easy to get wrong at. The question is
     * whether one *more* is allowed, so at limit 3 with 3 in hand the fourth
     * is refused — `$used > $limit` would have let it through.
     */
    public function testUsageAtTheLimitIsRefused(): void
    {
        $error = self::refusal(self::policy([self::quota(3)], 3));

        self::assertSame('QUOTA_EXCEEDED', $error->errorCode());
        self::assertSame(403, $error->statusCode());

        // Both numbers, so a client can tell its user how far over they are.
        self::assertSame(3, $error->details()['limit'] ?? null);
        self::assertSame(3, $error->details()['used'] ?? null);
    }

    public function testAnUnlimitedQuotaIsAlwaysAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        self::policy([self::quota(null)], 10_000)
            ->assertMayConsume(self::TENANT, self::PRODUCT, self::FEATURE);
    }

    /**
     * A capability you either have or do not is not a quota, and counting it
     * would be meaningless.
     */
    public function testABooleanCapabilityIsNotCounted(): void
    {
        $this->expectNotToPerformAssertions();

        $held = new Entitlement('advanced_3d', 'Advanced 3D', Entitlement::BOOLEAN, null, null, 'SUBSCRIPTION', null);

        self::policy([$held], 99)->assertMayConsume(self::TENANT, self::PRODUCT, 'advanced_3d');
    }

    /**
     * A limit nothing counts is not enforced — and the usage endpoint says so
     * rather than implying it is. Refusing at zero would be worse: it would
     * lock a customer out of something they paid for because the platform has
     * not built the meter yet.
     */
    public function testAnUnmeteredQuotaIsNotEnforced(): void
    {
        $this->expectNotToPerformAssertions();

        // A limit of one, and no meter registered for it at all.
        $policy = new QuotaPolicy(
            new InMemoryEntitlementRepository([self::TENANT . ':' . self::PRODUCT => [self::quota(1)]]),
            new UsageMeter([]),
        );

        $policy->assertMayConsume(self::TENANT, self::PRODUCT, self::FEATURE);
    }

    private static function quota(?int $limit): Entitlement
    {
        return new Entitlement(
            self::FEATURE,
            'Projects',
            Entitlement::QUOTA,
            'projects',
            $limit,
            'SUBSCRIPTION',
            null,
        );
    }

    /**
     * @param list<Entitlement> $entitlements
     */
    private static function policy(array $entitlements, int $used): QuotaPolicy
    {
        return new QuotaPolicy(
            new InMemoryEntitlementRepository([self::TENANT . ':' . self::PRODUCT => $entitlements]),
            new UsageMeter([self::FEATURE => new FixedUsage($used)]),
        );
    }

    private static function refusal(QuotaPolicy $policy): HttpException
    {
        try {
            $policy->assertMayConsume(self::TENANT, self::PRODUCT, self::FEATURE);
        } catch (HttpException $error) {
            return $error;
        }

        self::fail('The consumption was allowed.');
    }
}
