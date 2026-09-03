<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\Money;
use App\Sales\Domain\Quote;
use App\Sales\Domain\QuoteStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A quote lapses on the clock.
 *
 * This is the platform's oldest rule, applied for the fourth time: an offer's
 * commercial window, an entitlement's validity, a subscription's period, and
 * now a quote's. **A lapse is a fact about the clock, never about whether
 * something ran** — so these tests deliberately leave the status column
 * saying SENT while the date has passed, which is exactly the state a system
 * with no sweeper is in.
 */
#[CoversClass(Quote::class)]
#[CoversClass(QuoteStatus::class)]
final class QuoteExpiryTest extends TestCase
{
    public function testASentQuoteInsideItsWindowIsOpen(): void
    {
        $quote = self::quote(QuoteStatus::SENT, '+7 days');

        self::assertTrue($quote->isOpenAt(new DateTimeImmutable()));
        self::assertFalse($quote->hasLapsedAt(new DateTimeImmutable()));
    }

    public function testASentQuotePastItsDateHasLapsedEvenThoughNothingSweptIt(): void
    {
        // The column still says SENT. Nothing has run. The quote is gone all
        // the same, and honouring it would hold a price that expired.
        $quote = self::quote(QuoteStatus::SENT, '-1 day');

        self::assertFalse($quote->isOpenAt(new DateTimeImmutable()));
        self::assertTrue($quote->hasLapsedAt(new DateTimeImmutable()));
    }

    public function testTheBoundaryIsHalfOpen(): void
    {
        $moment = new DateTimeImmutable('2026-09-03T12:00:00+00:00');
        $quote = self::quote(QuoteStatus::SENT, null, $moment);

        // Valid *until* means up to but not including, matching the offer
        // window and the entitlement window rather than differing by a
        // second from either.
        self::assertFalse($quote->isOpenAt($moment));
        self::assertTrue($quote->isOpenAt($moment->modify('-1 second')));
    }

    /**
     * @return list<array{string}>
     */
    public static function undecidableStatuses(): array
    {
        return [
            [QuoteStatus::DRAFT],
            [QuoteStatus::ACCEPTED],
            [QuoteStatus::REJECTED],
            [QuoteStatus::EXPIRED],
            [QuoteStatus::CANCELLED],
        ];
    }

    #[DataProvider('undecidableStatuses')]
    public function testOnlyASentQuoteIsOpen(string $status): void
    {
        // A draft was never shown to anybody; the rest are already decided.
        self::assertFalse(self::quote($status, '+7 days')->isOpenAt(new DateTimeImmutable()));
    }

    public function testOnlyASentQuoteCanBeDecided(): void
    {
        self::assertTrue(QuoteStatus::permits(QuoteStatus::SENT, QuoteStatus::ACCEPTED));
        self::assertTrue(QuoteStatus::permits(QuoteStatus::SENT, QuoteStatus::REJECTED));

        // Accepting a draft would mean a customer agreed to something nobody
        // showed them.
        self::assertFalse(QuoteStatus::permits(QuoteStatus::DRAFT, QuoteStatus::ACCEPTED));
        self::assertFalse(QuoteStatus::permits(QuoteStatus::ACCEPTED, QuoteStatus::ACCEPTED));
        self::assertFalse(QuoteStatus::permits(QuoteStatus::EXPIRED, QuoteStatus::ACCEPTED));
        self::assertFalse(QuoteStatus::permits(QuoteStatus::REJECTED, QuoteStatus::ACCEPTED));
    }

    public function testAnUnknownStatusDecidesNothing(): void
    {
        self::assertFalse(QuoteStatus::permits('NONSENSE', QuoteStatus::ACCEPTED));
    }

    private static function quote(
        string $status,
        ?string $offset,
        ?DateTimeImmutable $validUntil = null,
    ): Quote {
        $until = $validUntil ?? (new DateTimeImmutable())->modify($offset ?? '+1 day');

        return new Quote(
            'q-1',
            't-1',
            'p-1',
            'v-1',
            $status,
            Money::of(2900, 'EUR'),
            Money::of(580, 'EUR'),
            Money::of(3480, 'EUR'),
            $until,
            [],
            new DateTimeImmutable(),
            null,
            new DateTimeImmutable(),
            [InvoiceLine::of(
                1,
                'Atlas Pro (v1) — subscription',
                1,
                Money::of(2900, 'EUR'),
                Money::zero('EUR'),
                2000,
                null,
            )],
        );
    }
}
