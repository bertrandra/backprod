<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * When an offer version may be sold.
 *
 * This is the one place the commercial window is decided (§12), so its edges
 * are tested here rather than inferred from an endpoint. Two properties do
 * the work: the window is half-open, and expiry is a fact about the clock
 * rather than about whether a job has run.
 */
#[CoversClass(OfferVersion::class)]
#[CoversClass(OfferCandidate::class)]
final class OfferVersionTest extends TestCase
{
    private const OPENS = '2026-03-01T00:00:00+00:00';
    private const CLOSES = '2026-04-01T00:00:00+00:00';

    /**
     * The window is half-open: open at the start, closed at the end. An
     * offer withdrawn "on 1 April" must not be sellable at midnight on the
     * 1st, and its replacement starting that instant must be — otherwise
     * either both or neither are on sale for one tick.
     */
    #[DataProvider('moments')]
    public function testTheWindowIsHalfOpen(string $moment, bool $expected): void
    {
        $version = self::version(OfferVersion::ACTIVE, self::OPENS, self::CLOSES);

        self::assertSame($expected, $version->isSellableAt(new DateTimeImmutable($moment)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function moments(): iterable
    {
        yield 'a moment before it opens' => ['2026-02-28T23:59:59+00:00', false];
        yield 'exactly when it opens' => [self::OPENS, true];
        yield 'in the middle' => ['2026-03-15T12:00:00+00:00', true];
        yield 'a microsecond before it closes' => ['2026-03-31T23:59:59.999999+00:00', true];
        yield 'exactly when it closes' => [self::CLOSES, false];
        yield 'after it closes' => ['2026-04-01T00:00:01+00:00', false];
    }

    /**
     * An open-ended version stays on sale until someone ends it.
     */
    public function testAVersionWithNoEndStaysSellable(): void
    {
        $version = self::version(OfferVersion::ACTIVE, self::OPENS, null);

        self::assertTrue($version->isSellableAt(new DateTimeImmutable('2030-01-01T00:00:00+00:00')));
    }

    /**
     * The reason status alone is not enough. A version can sit at ACTIVE
     * long after its window closed, because nothing has run to change it —
     * and asking the clock means the offer stops selling anyway.
     */
    public function testAnExpiredWindowOutranksAnActiveStatus(): void
    {
        $stale = self::version(OfferVersion::ACTIVE, self::OPENS, self::CLOSES);

        self::assertFalse($stale->isSellableAt(new DateTimeImmutable('2026-06-01T00:00:00+00:00')));
    }

    /**
     * And the reason the clock alone is not enough either: a draft inside
     * its intended window is not yet for sale.
     */
    #[DataProvider('unsellableStatuses')]
    public function testOnlyAnActiveVersionIsSellable(string $status): void
    {
        $version = self::version($status, self::OPENS, self::CLOSES);

        self::assertFalse($version->isSellableAt(new DateTimeImmutable('2026-03-15T12:00:00+00:00')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsellableStatuses(): iterable
    {
        yield 'draft' => [OfferVersion::DRAFT];
        yield 'expired' => [OfferVersion::EXPIRED];
        yield 'archived' => [OfferVersion::ARCHIVED];
    }

    /**
     * Versions supersede rather than accumulate. If a publishing mistake
     * leaves two overlapping, the customer should be quoted the newer terms,
     * not the oldest ones still technically open.
     */
    public function testTheNewestSellableVersionWins(): void
    {
        $candidate = new OfferCandidate(
            'offer-1',
            'pro-monthly',
            'Pro, monthly',
            new Plan('plan-1', 'PRO', 'Pro', 20),
            [
                self::version(OfferVersion::ACTIVE, self::OPENS, null, 2, 2900),
                self::version(OfferVersion::ACTIVE, self::OPENS, null, 1, 1900),
            ],
        );

        $sellable = $candidate->sellableAt(new DateTimeImmutable('2026-03-15T12:00:00+00:00'));

        self::assertNotNull($sellable);
        self::assertSame(2, $sellable->version);
        self::assertSame(2900, $sellable->priceMinorUnits);
    }

    public function testAnOfferWithNothingOnSaleResolvesToNull(): void
    {
        $candidate = new OfferCandidate(
            'offer-1',
            'pro-monthly',
            'Pro, monthly',
            new Plan('plan-1', 'PRO', 'Pro', 20),
            [self::version(OfferVersion::ACTIVE, self::OPENS, self::CLOSES)],
        );

        self::assertNull($candidate->sellableAt(new DateTimeImmutable('2026-06-01T00:00:00+00:00')));
    }

    private static function version(
        string $status,
        string $from,
        ?string $until,
        int $number = 1,
        int $price = 1900,
    ): OfferVersion {
        return new OfferVersion(
            'version-' . $number,
            $number,
            $status,
            'MONTHLY',
            $price,
            'EUR',
            new DateTimeImmutable($from),
            $until === null ? null : new DateTimeImmutable($until),
            [],
        );
    }
}
