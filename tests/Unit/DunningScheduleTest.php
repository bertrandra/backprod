<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Commerce\Domain\DunningSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * When a product chases an unpaid invoice (spec §5.2).
 *
 * The schedule is **configuration and not a constant**, so what is tested here
 * is the reading of it: which documents an operator can be trusted to have
 * meant, which are unusable, and what a given number of days overdue asks for.
 */
#[CoversClass(DunningSchedule::class)]
final class DunningScheduleTest extends TestCase
{
    public function testAProductsOwnDaysAreRead(): void
    {
        $schedule = DunningSchedule::fromConfiguration(['dunning' => ['retries' => [2, 9]]]);

        self::assertSame([2, 9], $schedule->retries);
        self::assertSame(2, $schedule->graceDays());
    }

    /**
     * Absent means the default, which is the **opposite** of `FreemiumPeriod`
     * and deliberately so: guessing a free period gives something away, and
     * giving away is the direction that cannot be taken back; never chasing an
     * unpaid invoice loses the money the platform is owed and leaves the product
     * running for free.
     */
    public function testSilenceIsTheDefaultAndNotSilenceForEver(): void
    {
        self::assertSame(DunningSchedule::DEFAULT_RETRIES, DunningSchedule::fromConfiguration([])->retries);
        self::assertSame([1, 3, 7], DunningSchedule::DEFAULT_RETRIES);
    }

    /**
     * A document nobody can read is not a decision an operator made.
     *
     * Out of order is refused rather than sorted, and that is the interesting
     * one: a list somebody meant as `[7, 3, 1]` and a list they mistyped are the
     * same bytes, so sorting would silently pick one reading of two.
     *
     * @param array<string, mixed> $document
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusable')]
    public function testAnUnusableDocumentReadsAsNoDocument(array $document): void
    {
        self::assertSame(
            DunningSchedule::DEFAULT_RETRIES,
            DunningSchedule::fromConfiguration(['dunning' => $document])->retries,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusable(): iterable
    {
        yield 'no retries at all' => [[]];
        yield 'an empty list' => [['retries' => []]];
        yield 'out of order' => [['retries' => [7, 3, 1]]];
        yield 'the same day twice' => [['retries' => [3, 3]]];
        yield 'day zero, which is the due date itself' => [['retries' => [0, 3]]];
        yield 'not whole days' => [['retries' => [1.5, 3]]];
        yield 'further out than a schedule goes' => [['retries' => [1, 400]]];
        yield 'more chases than anybody meant' => [['retries' => range(1, 20)]];
    }

    /**
     * The **highest** step whose day has passed, not the next after the last.
     *
     * A pass that has not run for a week must not work through three chases in
     * three minutes: that arrives as three mails in one inbox and reads as a
     * fault. Skipping to where the clock is means a queue that fell behind
     * catches up in one notice.
     */
    public function testTheStepDueIsWhereTheClockIsAndNotWhereTheQueueLeftOff(): void
    {
        $schedule = DunningSchedule::ofDays([1, 3, 7]);

        self::assertNull($schedule->stepDueAfter(0), 'inside the grace');
        self::assertSame(1, $schedule->stepDueAfter(1));
        self::assertSame(1, $schedule->stepDueAfter(2));
        self::assertSame(2, $schedule->stepDueAfter(3));
        self::assertSame(3, $schedule->stepDueAfter(7));
        // Past the last, it stays at the last: "then stop" is the absence of a
        // next step, and the notice for this one already exists.
        self::assertSame(3, $schedule->stepDueAfter(90));
    }

    /** What it reads is what it writes, so a seeded product reads back. */
    public function testItRoundTripsThroughTheConfigurationDocument(): void
    {
        $written = DunningSchedule::ofDays([4, 11])->asConfiguration();

        self::assertSame(['retries' => [4, 11]], $written);
        self::assertSame([4, 11], DunningSchedule::fromConfiguration(['dunning' => $written])->retries);
    }
}
