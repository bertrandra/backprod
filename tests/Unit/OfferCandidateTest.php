<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which version is on sale right now.
 *
 * This lives as a unit test because the interesting case can no longer be
 * built in the database. Two ACTIVE versions of one offer whose windows
 * overlap are refused by `offer_versions_one_on_sale_at_a_time` (ADR-033),
 * and that is the right place to stop them — but `sellableAt` still has to
 * resolve an overlap sanely, for rows that predate the constraint or arrive
 * by some path that is not the authoring endpoint.
 *
 * So the constraint prevents the mistake and this proves the defence behind
 * it still works, which neither could show alone.
 */
#[CoversClass(OfferCandidate::class)]
final class OfferCandidateTest extends TestCase
{
    private const NOW = '2026-06-15T12:00:00+00:00';

    public function testTheNewestOfTwoOverlappingVersionsWins(): void
    {
        $candidate = self::offerWith([
            self::version(3, OfferVersion::ACTIVE, '-1 day', null),
            self::version(2, OfferVersion::ACTIVE, '-30 days', null),
        ]);

        $sellable = $candidate->sellableAt(new DateTimeImmutable(self::NOW));

        // Versions supersede rather than accumulate: an overlap resolves to
        // the most recent terms, not the oldest still standing.
        self::assertNotNull($sellable);
        self::assertSame(3, $sellable->version);
    }

    public function testADraftIsNeverSellableHoweverRecent(): void
    {
        $candidate = self::offerWith([
            self::version(4, OfferVersion::DRAFT, '-1 day', null),
            self::version(3, OfferVersion::ACTIVE, '-30 days', null),
        ]);

        $sellable = $candidate->sellableAt(new DateTimeImmutable(self::NOW));

        self::assertNotNull($sellable);
        self::assertSame(3, $sellable->version);
    }

    public function testAVersionOutsideItsWindowIsNotSellableEvenWhileActive(): void
    {
        // The half of `isSellableAt` that asks the clock rather than the
        // status: a window that has closed is closed whether or not a job has
        // run to mark the row EXPIRED.
        $candidate = self::offerWith([self::version(1, OfferVersion::ACTIVE, '-30 days', '-1 day')]);

        self::assertNull($candidate->sellableAt(new DateTimeImmutable(self::NOW)));
    }

    public function testAVersionThatHasNotOpenedYetIsNotSellable(): void
    {
        $candidate = self::offerWith([self::version(1, OfferVersion::ACTIVE, '+1 day', null)]);

        self::assertNull($candidate->sellableAt(new DateTimeImmutable(self::NOW)));
    }

    public function testAnOfferWithNoVersionsSellsNothing(): void
    {
        self::assertNull(self::offerWith([])->sellableAt(new DateTimeImmutable(self::NOW)));
    }

    /**
     * @param list<OfferVersion> $versions newest first, as storage returns them
     */
    private static function offerWith(array $versions): OfferCandidate
    {
        return new OfferCandidate(
            'offer-1',
            'pro-monthly',
            'Pro Monthly',
            new Plan('plan-1', 'PRO', 'Pro', 20),
            $versions,
        );
    }

    private static function version(int $number, string $status, string $from, ?string $until): OfferVersion
    {
        $now = new DateTimeImmutable(self::NOW);

        return new OfferVersion(
            'version-' . $number,
            $number,
            $status,
            'MONTHLY',
            2900,
            'EUR',
            $now->modify($from),
            $until === null ? null : $now->modify($until),
            [],
        );
    }
}
