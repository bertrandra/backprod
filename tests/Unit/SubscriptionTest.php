<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscription;
use App\Commerce\Service\Subscriptions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * When a subscription actually entitles a tenant, and what a change to it is
 * called.
 *
 * The first is the lesson the offer's commercial window taught, applied
 * again: a lapse is a fact about the clock, not about whether a job has run.
 */
#[CoversClass(Subscription::class)]
#[CoversClass(Subscriptions::class)]
final class SubscriptionTest extends TestCase
{
    private const ENDS = '2026-04-01T00:00:00+00:00';

    #[DataProvider('moments')]
    public function testAPeriodEndsOnTheClock(string $moment, bool $expected): void
    {
        $subscription = self::subscription(Subscription::ACTIVE, self::ENDS);

        self::assertSame($expected, $subscription->isLiveAt(new DateTimeImmutable($moment)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function moments(): iterable
    {
        yield 'inside the period' => ['2026-03-15T12:00:00+00:00', true];
        yield 'a microsecond before it ends' => ['2026-03-31T23:59:59.999999+00:00', true];
        yield 'exactly when it ends' => [self::ENDS, false];
        yield 'after it ends' => ['2026-04-02T00:00:00+00:00', false];
    }

    /**
     * The failure this design exists to prevent: a subscription still marked
     * ACTIVE weeks after its period ended, because nothing swept it. Asking
     * the clock means the tenant stops being entitled anyway.
     */
    public function testAnEndedPeriodOutranksAnActiveStatus(): void
    {
        $stale = self::subscription(Subscription::ACTIVE, self::ENDS);

        self::assertFalse($stale->isLiveAt(new DateTimeImmutable('2026-06-01T00:00:00+00:00')));
    }

    /**
     * A CUSTOM period has no computable end and runs until someone ends it.
     * Null must read as open-ended, never as expired.
     */
    public function testASubscriptionWithNoPeriodEndIsStillLive(): void
    {
        $open = self::subscription(Subscription::ACTIVE, null);

        self::assertTrue($open->isLiveAt(new DateTimeImmutable('2099-01-01T00:00:00+00:00')));
    }

    #[DataProvider('endedStatuses')]
    public function testOnlyAnActiveSubscriptionIsLive(string $status): void
    {
        $subscription = self::subscription($status, self::ENDS);

        self::assertFalse($subscription->isLiveAt(new DateTimeImmutable('2026-03-15T12:00:00+00:00')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endedStatuses(): iterable
    {
        yield 'cancelled' => [Subscription::CANCELLED];
        yield 'expired' => [Subscription::EXPIRED];
    }

    /**
     * Cancelling does not remove the month already paid for.
     */
    public function testASubscriptionScheduledToEndIsStillLiveUntilItDoes(): void
    {
        $ending = self::subscription(Subscription::ACTIVE, self::ENDS, cancelAtPeriodEnd: true);
        $inside = new DateTimeImmutable('2026-03-15T12:00:00+00:00');

        self::assertTrue($ending->isLiveAt($inside));
        self::assertTrue($ending->isEndingAt($inside));
        self::assertFalse($ending->isEndingAt(new DateTimeImmutable('2026-05-01T00:00:00+00:00')));
    }

    /**
     * Two numbers from the database, never a plan name (§13).
     */
    #[DataProvider('rankChanges')]
    public function testDirectionComesFromRank(int $from, int $to, string $expected): void
    {
        self::assertSame($expected, Subscriptions::directionBetween($from, $to));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function rankChanges(): iterable
    {
        yield 'to a higher tier' => [10, 20, Subscriptions::UPGRADE];
        yield 'to a lower tier' => [20, 10, Subscriptions::DOWNGRADE];
        // Moving between two offers on the same tier — monthly to yearly,
        // say — is neither, and calling it one would put a wrong word in the
        // audit trail.
        yield 'within the same tier' => [20, 20, Subscriptions::LATERAL];
    }

    private static function subscription(
        string $status,
        ?string $periodEnd,
        bool $cancelAtPeriodEnd = false,
    ): Subscription {
        $version = new OfferVersion(
            'version-1',
            1,
            OfferVersion::ACTIVE,
            'MONTHLY',
            2900,
            'EUR',
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            [],
        );

        return new Subscription(
            'sub-1',
            'tenant-acme',
            'prod-atlas',
            new SubscribedOffer('offer-1', 'pro', 'Pro', new Plan('plan-1', 'PRO', 'Pro', 20), $version),
            $status,
            new DateTimeImmutable('2026-03-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-03-01T00:00:00+00:00'),
            $periodEnd === null ? null : new DateTimeImmutable($periodEnd),
            $cancelAtPeriodEnd,
            null,
            $status === Subscription::ACTIVE ? null : new DateTimeImmutable('2026-03-10T00:00:00+00:00'),
        );
    }
}
